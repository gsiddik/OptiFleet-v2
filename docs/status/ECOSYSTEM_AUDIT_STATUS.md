# Ecosystem audit fixes - OptiFleet status

Branch `claude/project-thread-0cf20u`. Checkpoint between sessions; the repository is the source of truth.
Statuses: CONFIRMED / ALREADY_FIXED / NOT_REPRODUCIBLE / FIXED_AND_TESTED / BLOCKED. Test commands run from `backend/` with
`php artisan test` against PostgreSQL (port 55434) + MongoDB (27018).

| Finding | Status | Evidence (file) | Tests | Commit | Remaining |
|---|---|---|---|---|---|
| F1 A02/R01/R05 central governance | FIXED_AND_TESTED (batch 1) | `app/Domain/Governance/CentralGovernance.php`, `PermissionService.php:28`, `EntitlementService.php:32`, `TenantContextMiddleware.php:73`, `routes/api/app.php:869` | `tests/Feature/Governance/*` | 4a85f7b (core), 7555b9a (operations) | Nexus gaps below; per-tenant cutover is an operator step |
| F2 R08/A25 delegation from Fleet UI | FIXED_AND_TESTED (browser smoke NOT_RUN) | `Delegation/*`, `DelegationController.php`, `pages/tenant/access/Central*Page.tsx`, `FleetManifest.php` | `DelegationTest` (16), `FleetManifestTest`, `delegation.test.ts` | 38320a4 (API), 9105445 (UI) | Playwright 1440/820/390 (in F6) |
| F3 A29/R06 product switcher | FIXED_AND_TESTED (browser smoke NOT_RUN) | `ProductSwitcher.php`, `ProductSwitcherController.php`, `OptinexusOidcClient::authorizationUrl`, `components/AppSwitcher.tsx`, `pages/LoginPage.tsx` | `ProductSwitcherTest` (26), `ssoLogin.test.ts` | next commit | Playwright (in F6); real OptiNexus |
| F4 A01/A17/A18/A19/A22 OA6 producer | CONFIRMED | `AccountingFeed*` | - | - | all |
| F5 A10 telematics | CONFIRMED | `VehicleOdometerService`, `OptinexusSyncService` | - | - | all |
| F6 A12/A15 frontend | CONFIRMED | single 2.37 MB main bundle | - | - | all |

## F1 decisions

- Mode per tenant (`tenants.governance_mode`, default LOCAL): a legacy install keeps working unchanged until an operator runs
  `optinexus:governance:enable`; the decision is never automatic. Inventory, linking and migration plan: `docs/integration/CENTRAL_GOVERNANCE.md`.
- CENTRAL: permissions = OptiNexus grants, narrowed (never widened) by local roles; modules = OptiNexus capabilities; the four core
  modules come with the application. Password sessions cannot enter (SSO ability `sso` required).
- Bulk only: one effective-permissions call per user and window, one commercial-context per tenant and window (tested); product-context
  only when nothing was granted. TTL <= 300 s, hard freshness check on every read, cache dropped on failure, single-flight lock.
- Fail closed: `503 GOVERNANCE_UNAVAILABLE` (+ Retry-After), `401 SESSION_ENDED_CENTRALLY`, `403 IDENTITY_NOT_LINKED`, `409 GOVERNED_CENTRALLY`.
- Manifest v2: `optinexus:manifest`; `delegable` is an explicit reviewed list (148 of 259), default false for anything new.
- Route matrix is generated from the live route table (`optinexus:route-matrix`, `--check` in a test).

## F3 decisions

- Server-side only: `GET /auth/products` (names, no links, cached <= 300 s) and `POST /auth/products/launch` (fresh answer, only a
  code OptiNexus offers, URL + `tenant_hint`). `allowed=false` ends the session; outage lists nothing. Details: `CENTRAL_GOVERNANCE.md`.
- The `launch_url` registered for Fleet in OptiNexus should be the SPA `/login`; `tenant_hint` is accepted only as a UUID.

## Contract gaps needed from OptiNexus

1. App-scoped bulk entitlement (`entitled_capabilities` on effective-permissions); today `commercial-context` (tenant-wide, scope `commercial.read`, no binding check).
2. Bulk "who holds permission X" (approver resolution scans <= 200 linked members).
3. Per-service-account scope allow-list (any account may ask for `tenant.delegate`).

## Not run / limits

- No real OptiNexus: all contract tests use FakeNexus built from the documented JSON.
