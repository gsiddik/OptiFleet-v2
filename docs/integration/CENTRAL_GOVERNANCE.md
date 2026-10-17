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
