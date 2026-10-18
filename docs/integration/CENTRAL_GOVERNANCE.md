# Central governance (OptiNexus as the authority)

Audit findings A02, R01, R05. Contract: OptiNexus `docs/ecosystem/GOVERNANCE_CONTRACTS.md`. Code: `backend/app/Domain/Governance/`.
Route-by-route gates: [AUTHORIZATION_ROUTE_MATRIX.md](AUTHORIZATION_ROUTE_MATRIX.md) (generated, tested for staleness).

## Two modes, per tenant

| | LOCAL (legacy, default) | CENTRAL (target) |
|---|---|---|
| Stored in | `tenants.governance_mode` (CHECK: `LOCAL`/`CENTRAL`; CENTRAL requires `optinexus_tenant_id`) | same |
| Who decides | OptiFleet roles, data scopes, module entitlements | OptiNexus: effective permissions + subscription capabilities |
| Local roles | grant | only **restrict** (intersection with the central grants; a user with no local role gets exactly the central grants) |
| Local data scopes (branch/workshop/warehouse/own) | apply | apply (they only narrow; OptiNexus gives role scope, not data scope) |
| Sign-in | password or SSO | SSO only; a password session is refused (`401 SESSION_ENDED_CENTRALLY`) |
| New local users / entitlement edits | allowed | refused, `409 GOVERNED_CENTRALLY` (`not.central` middleware) |
| OptiNexus unreachable | n/a | fail closed, `503 GOVERNANCE_UNAVAILABLE` (+ `Retry-After`), nothing falls back |

Fleet starts and runs without OptiNexus, OptiRadar and OptiEntry: `OPTINEXUS_ENABLED=false` keeps every tenant LOCAL and
nothing in this document applies. A tenant only becomes CENTRAL through an explicit operator action (below).

## What is asked of OptiNexus, and when

| Need | Call (service account, least-privilege scope) | Cache | Calls |
|---|---|---|---|
| Permissions of a user in a tenant | `POST /authorization/effective-permissions` (`authorization.check`) | <= `min(ttl_seconds, 300)` | one per user and tenant per window, never per permission |
| Why access is empty (session end vs. no roles) | `POST /authorization/product-context` | same | only when nothing was granted |
| Modules the tenant holds | `GET /tenants/{id}/commercial-context` (`commercial.read`) | same | one per tenant per window |

Keys carry the OptiNexus tenant and user ids. Every read re-checks freshness; on any failure the cached entry is dropped (no
stale grant is ever used or widened). A single-flight lock makes a cold cache cost one call. Timeouts: 5 s / 3 s connect; one
`Retry-After` wait of <= 2 s on HTTP 429, otherwise fail closed. Revocation latency is at most one TTL (<= 300 s); back-channel
logout and access-revoked events end sessions at once and drop the cached answers.

Permission key in OptiNexus = `optifleet.<local permission name>`; module = capability `optifleet.<module code lower case>`
(the four core modules come with the application while the tenant is ACTIVE).

## Where it is enforced

- **Routes**: `TenantContextMiddleware` (SSO session + access snapshot) -> module gate (`EntitlementService`) -> permission gate
  (`PermissionService`). All three call `CentralGovernance` for CENTRAL tenants; no controller re-implements the decision.
- **Services**: `ApprovalResolver` (approver = let in by OptiNexus and, for a permission step, granted it; local roles never add;
  an outage notifies nobody and refuses the decision), `PermissionService::userHasPermission` everywhere else.
- **Exports, uploads, downloads, PDFs**: ordinary tenant routes, each with a permission (tested: no such route is ungated).
- **Background jobs / streams**: analytics + intelligence runs filter eligible tenants through the central module check; the eight
  jobs re-check `ChecksCentralEntitlement::stillEntitled()` when they start (a withdrawn module stops queued work).
- **Audit**: tenant mode switches (`governance.enabled|disabled`), JIT provisioning (`provisioned_from_optinexus`,
  `optinexus_identity_linked`, `membership_created_from_optinexus`); denials are logged as warnings without personal data.

## Identity in CENTRAL tenants

First SSO sign-in creates a projection: no usable password, TENANT data scope only for a new membership, no local role. An existing
local account is linked once by verified e-mail; an account bound to another OptiNexus subject is never rebound; an unverified
e-mail creates nothing; a membership an administrator switched off stays off.

## Users and roles from the OptiFleet UI (delegation, audit R08/A25)

In a CENTRAL tenant `/app/access/users` and `/app/access/roles` show the central pages instead of the local ones. OptiFleet keeps no
copy of users, roles or assignments: `Api/Tenant/DelegationController` (thin) -> `TenantDelegationService` -> `NexusDelegationClient`
(`tenant.delegate`, 5 s / 3 s timeouts, no retry of a change after a lost connection) -> OptiNexus delegation API.

| Route (`/api/v1/app/delegation/...`) | Fleet gate | Delegation call |
|---|---|---|
| `GET catalog` | `role.view` | catalog: delegable permissions (grouped Module > Feature > Action) + assignable roles |
| `GET users` | `user.view` | list (search, paging) |
| `POST users` | `user.create` | **deprecated** (see Invitations below): add or link by e-mail (`created`/`linked`/`existing`); no password field exists |
| `GET invitations` | `user.view` | invitations of this organization and application (`status`, `search`, `per_page`), newest first |
| `POST invitations` | `user.create` | invite a NEW person: `email`, `name`, `role_ids[]`, `locale` (`en`/`id`, defaults to the UI language) |
| `POST invitations/{id}/resend` | `user.create` | new link (the old one stops working), PENDING or EXPIRED only |
| `POST invitations/{id}/revoke` | `user.update` | `reason?`; already revoked answers unchanged |
| `PUT users/{id}/roles` | `user.assign` | replace the delegated roles |
| `DELETE users/{id}` | `user.update` | remove the application from the person |
| `POST roles`, `PUT roles/{id}`, `DELETE roles/{id}` | `role.create`, `role.update` (+ `role.assign_permission` to change permissions) | custom roles |

- Acting user = the signed-in person's OptiNexus subject, taken server-side; a value in the request is ignored. The tenant is the
  session's tenant. Ids that go into a URL must be UUIDs.
- OptiNexus decides Admin Tenant, delegable set, assignable roles and every protection; OptiFleet only adds its own permission gate in front
  (`user.*` / `role.*` are not delegable, so an operator grants them to the organization administrators).
- Every documented refusal is mapped to a status and a translated message (`App\Domain\Governance\Delegation\DelegationErrors`, en + id);
  the English `code` stays in the body with `correlation_id`, `retryable`, `outcome_unknown`. A lost connection during a change answers
  `503 DELEGATION_UNAVAILABLE` with `outcome_unknown: true`; the UI tells the person to check the list before retrying.
- Audit (`audit_logs`, resource type `delegation`): `delegation.user_created|linked|existing`, `user_roles_set`, `user_access_revoked`,
  `role_created|updated|deleted`, and `delegation.refused` (code + correlation id) for every refusal by OptiNexus; outages are only logged.
- After a change the cached access answers of the people it affects are dropped (single person or all members), so a revocation is
  in force on the next request instead of within one TTL.
- Local data scope stays local: the users page opens it for people who already signed in here (`local_member_id`).
- Seats are counted and enforced by OptiNexus (contract section 2d, see Seats below); Fleet's local capacity check applies to LOCAL tenants only.

### Invitations (OptiNexus contract GOVERNANCE_CONTRACTS.md section 2b)

A new person is invited, not created: OptiNexus e-mails the link, the person sets their own password on an OptiNexus page (which also
verifies the e-mail; Fleet keeps requiring a verified e-mail at SSO) and then signs in here with OptiNexus. Nothing is granted until
they accept. Fleet keeps no copy of identities or invitations; no password and no link or token passes through Fleet.

- Same rules as the other delegated routes: Fleet permission gate first, CENTRAL tenants only, acting user = the signed-in person's
  OptiNexus subject, tenant from the session; `{id}` must be a UUID (anything else is a 404 without a call). An invitation of another
  organization is `404 INVITATION_NOT_FOUND` (OptiNexus decides; the path always carries the session's own OptiNexus tenant).
- The view is a whitelist of the documented fields (`DelegatedInvitation`): a token field would never reach the browser, the audit trail or the logs.
- Codes mapped (en + id): `INVITATION_PENDING` (409, `details.invitation_id`), `ALREADY_MEMBER` (409), `ROLE_NOT_ASSIGNABLE` (422),
  `INVITATION_NOT_FOUND` (404), `INVITATION_NOT_PENDING` (409, `details.status`), `INVITATION_RESEND_COOLDOWN` and `INVITATION_RATE_LIMITED`
  (429 with `Retry-After` and `retry_after_seconds`; `NexusApiClient` returns these two instead of sleeping, nothing is retried),
  `INVITATION_RESEND_LIMIT` (409), and `DIRECT_USER_CREATION_DISABLED` (410) for the deprecated add-user route.
- Audit: `delegation.invitation_created|resent|revoked`, `delegation.refused` for refusals (a 429 decided nothing and is only logged).
  Logs carry codes and ids only, never an e-mail address.
- UI: `/app/access/users` has Users and Invitations tabs; "Invite user" replaced "Add user". Resend / Revoke follow `can_resend` / `can_revoke`
  from OptiNexus and the `user.create` / `user.update` permissions; after a cooldown refusal Resend stays off for the wait OptiNexus asked for.

### Seats (OptiNexus contract GOVERNANCE_CONTRACTS.md section 2d, owner decision 2026-10-18)

OptiNexus counts seats per tenant and application and refuses an addition that does not fit; Fleet keeps no counter (a test fails if `user_limit` appears in the app,
routes or database folders). Fleet declares **no `user_limit` entitlement**, so for Fleet OptiNexus answers `NOT_CONTROLLED`: no limit is applied and none is invented here.
Fleet still passes the picture on, so the day a tenant's Fleet subscription carries one nothing needs to change:

- `GET users` and `GET invitations` return `seats` next to `data` and `meta` (state, limit, used, reserved by open invitations, available, over_limit, over_by, can_add,
  blocked_reason; an answer that does not look like the contract is `null`). The Users and Invitations tabs show it (used, reserved, available, limit; over-limit as "over by n", never a negative number;
  a missing, invalid or unreadable limit blocks additions and is never shown as unlimited; `NOT_CONTROLLED` says no seat limit applies to this product).
- Refusals are passed through with OptiNexus' own figures (`details`: `limit`, `used`, `reserved`, `available`, `over_limit`, `recovery`): `SEAT_LIMIT_REACHED`, `SEAT_OVER_LIMIT`,
  `SEAT_ENTITLEMENT_MISSING`, `SEAT_ENTITLEMENT_INVALID` (409) and the transient `SEAT_ENTITLEMENT_UNAVAILABLE`, `SEAT_CONTENTION` (503 with `Retry-After` / `retry_after_seconds`; `outcome_unknown` is false:
  nothing was added; `NexusApiClient` returns these two 503 answers instead of reporting an outage). Messages en + id (`errors.delegation.seat*`, `delegation.seats.*`).

## Product switcher and switching sign-in (audit A29/R06)

The switcher in the header is what OptiNexus answers to `POST /authorization/product-context`, never a list kept here
(`ProductSwitcher`, `ProductSwitcherController`; own-account routes, session only, token ability `sso`):

| Route (`/api/v1/auth/...`) | Behaviour |
|---|---|
| `GET products` | `{available, reason, products[{code,name}], expires_at}`; no link is sent. Cached at most `min(ttl, 300 s)` per OptiNexus tenant + subject. `available:false` with `reason` `not_sso_session` / `not_linked` / `provider_unavailable` (fail closed: nothing listed while OptiNexus cannot answer, no stale list past its expiry) |
| `POST products/launch {code}` | Always asks OptiNexus again, then returns `{code,url}` = the product's `launch_url` + `tenant_hint=<OptiNexus tenant id>` (existing query kept). A code that is not in that fresh answer: `403 PRODUCT_NOT_AVAILABLE` (direct targets refused). OptiNexus unreachable: `503 GOVERNANCE_UNAVAILABLE`, no URL |

- `allowed=false` (reason in the logout vocabulary) ends the local session on either call: token deleted, caches dropped,
  `401 SESSION_ENDED_CENTRALLY` with `reason`. For a CENTRAL tenant the same check also runs on every request (access answer, <= 300 s).
- Never offered: the current product, `cgo` (governance portal, also filtered here in case the answer ever contained it), links that point
  to this application's frontend or to the OptiNexus origin, non-http(s) / credential-bearing / unparsable links, plain http outside
  `local`/`testing`. Audit: `product.launched`, `product.launch_refused` (resource type `product`).
- Receiving side: `GET /api/v1/auth/sso/redirect?tenant_hint=` and the SPA `/login?tenant_hint=` forward the hint to `/oidc/authorize`
  only when it is a UUID (anything else is dropped). The login page starts SSO by itself for a valid hint (also for a person signed in
  to another tenant: memberships carry `optinexus_tenant_id`); `?sso_error=` never restarts it, so there is no loop. Register Fleet's
  `launch_url` in OptiNexus as the SPA `/login` address.
- The static `apps` list of the ID token is still returned by `/auth/sso/exchange` for compatibility but is no longer used for switching.
- Tests: `tests/Feature/Governance/ProductSwitcherTest.php` (FakeNexus + JSON fixtures in `tests/fixtures/nexus/` written from
  GOVERNANCE_CONTRACTS.md section 3, including unsupported `schema_version` and an unbound service account).

## Legacy installs: inventory, linking, migration plan (no automatic migration)

1. Inventory (read only): `php artisan optinexus:governance:inventory [--tenant=CODE] [--json]` lists per tenant: mode, linked
   to OptiNexus, active / linked / local-only members, local role assignments, data scopes, local module entitlements.
2. Link the tenant: set `tenants.optinexus_tenant_id` to the OptiNexus tenant (an operator decision; never guessed or matched
   by name). Import the manifest: `php artisan optinexus:manifest --output=fleet-manifest.json`, then in OptiNexus
   `POST /applications/{id}/manifest` (dry run first). Delegable permissions are the reviewed list in
   `config/governance_delegation.php`.
3. In OptiNexus: assign the Fleet application, subscribe the capabilities, create the users (or let them be linked on first SSO
   sign-in by e-mail), and grant them application roles equal to what their local roles gave. Local roles are **not** copied.
4. Check: `php artisan optinexus:governance:enable CODE --check` (read only) - blockers (integration/SSO/service account not
   configured, tenant not linked, OptiNexus unreachable or tenant not ACTIVE, none of up to 20 sampled linked members holds any
   Fleet permission) and warnings (local-only members who will need SSO, local roles that become restrictions, local module
   entitlements that stop counting).
5. Cutover: `php artisan optinexus:governance:enable CODE --by=<platform operator e-mail>`: re-checks under a row lock, sets CENTRAL,
   revokes the tenant's password sessions, audits. Local roles stay (restrictions), nothing is deleted or copied.
6. Break-glass: `php artisan optinexus:governance:disable CODE --by=<e-mail> --reason="<>=10 chars>" --confirm` returns the tenant
   to LOCAL (OptiNexus revocations stop applying while LOCAL). Audited; for incidents only.

## Service account of Fleet in OptiNexus

Bound to the `optifleet` application (never `cgo`), scopes `authorization.check`, `commercial.read`, `tenant.delegate` (+ the gateway
scopes already used for odometer and events). Env: `OPTINEXUS_GATEWAY_CLIENT_ID/_SECRET`, `OPTINEXUS_GOVERNANCE_TTL` (<= 300),
`OPTINEXUS_APPLICATION_CODE` (default `optifleet`).

## Contract gaps (needed from OptiNexus)

1. **App-scoped bulk entitlement.** `effective-permissions` carries no capability entitlement, so Fleet reads `commercial-context`, which
   is tenant-wide (not application-scoped), needs the extra scope `commercial.read`, and is not checked against the service account's
   tenant binding. Proposal: additive `entitled_capabilities: [code]` on `effective-permissions` / `product-context`.
2. **Bulk "who holds permission X".** Approver resolution scans up to `approver_scan_limit` (200) linked members, one cached lookup
   each. Proposal: `POST /authorization/permission-holders {tenant_id, permission}` -> user ids, ttl.
3. **Scope restriction of service accounts.** Any service account may request `tenant.delegate`; only the application binding limits it.
   Proposal: allowed-scope list per service account.
