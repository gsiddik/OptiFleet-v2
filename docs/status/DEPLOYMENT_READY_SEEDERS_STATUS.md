# Deployment-Ready Seeders — Status

Tracks the deployment-readiness seeder audit and implementation. This is a
separate initiative from the Phase 6/7 Analytics/Intelligence track (see
`PHASE6_STATUS.md`/`PHASE7_STATUS.md`) and from the Tenant Portal
Alignment track (see `TENANT_PORTAL_ALIGNMENT_STATUS.md`, merged into
`main` at `2d001c8`, PR #1).

## Baseline

- **Base branch**: `main`
- **Base SHA**: `2d001c8` (merge commit for PR #1 — Tenant Portal
  requirement alignment)
- **Working branch**: `improvement/deployment-seeders`, created from the
  synchronized `main` per explicit owner instruction (seeder work must not
  continue from the old Tenant Portal feature branch).

## Problem

Before this initiative, `php artisan migrate && php artisan db:seed` on a
completely fresh environment produced **no usable production system**:
`DatabaseSeeder` unconditionally ran `DemoDataSeeder` (two demo tenants +
a hardcoded Platform Superadmin login, `admin@optifleet.test`/`password`),
`CommercialSeeder` (demo subscription-scenario tenants), `OperationsSeeder`
(a full demo Work Order history), and `SupplyChainSeeder` (a demo supply
chain hard-coupled to the demo tenant `ALPHA` via `firstOrFail()`).

This meant:
- A real production deployment could not skip demo data without also
  losing genuinely required global reference data (Product Category, UOM,
  Tire/Tool/Equipment reference data, the `Platform Superadmin` role, the
  default commercial bundle/pricing catalog) — that reference data only
  ever existed as an incidental side effect of the demo-tenant-coupled
  seeders, with no standalone production-safe seeder anywhere.
- `SupplyChainSeeder`'s Tire reference rows were tenant-scoped to `ALPHA`
  specifically rather than global, so even a fresh non-demo tenant created
  in production would have had zero Tire reference options.
- The only Platform Superadmin USER anywhere in the codebase was
  `DemoDataSeeder`'s hardcoded `admin@optifleet.test`/`password` — running
  demo data in production would ship a publicly-known credential; skipping
  it left no way to reach platform-level access at all.

## Architecture decision: production bootstrap vs. dev/demo, cleanly split

`DatabaseSeeder` (the default `db:seed` target) is now the production-safe
bootstrap path only — every seeder it calls is idempotent
(`updateOrCreate`/`firstOrCreate` on stable codes, never blind `create()`),
global (never tenant- or user-specific), and contains no demo/random/
destructive data. Demo/development data moved to a new, explicitly opt-in
`DevDemoSeeder` (`php artisan db:seed --class=DevDemoSeeder`), never run by
default.

New files:
- `ProductReferenceDataSeeder` — the global (`tenant_id=null`,
  `is_system=true`) counterpart to what was only ever an incidental
  side-effect of the demo-coupled `SupplyChainSeeder`: 6 Product
  Categories (one per Item Type), 5 UOM, Tire reference data (Load Index/
  Speed Rating/Ply Rating/TRA Code+Star Rating), 6 Tool Types, 9 Equipment
  Types, 5 Storage Requirements. Follows the same global-vs-tenant-scoped
  pattern already used by `VehicleCategory`/`ComponentGroup`/`WorkerType`
  (`(tenant_id, code)` unique constraint — tenants can still layer their
  own custom rows on top).
- `PlatformSuperadminRoleSeeder` — extracts only the `Platform Superadmin`
  ROLE (safe in every environment) out of its prior entanglement with
  `DemoDataSeeder`'s hardcoded admin USER. The role's permission sync is
  idempotent (`sync()` against the current `scope=platform` permission
  set).
- `CommercialCatalogSeeder` — reuses `CommercialSeeder::seedBundlesAndPricing()`
  (now `public`, logic untouched) to seed the 5 default sellable bundles/
  pricing as real reference data, without running any of
  `CommercialSeeder`'s demo-tenant subscription-scenario methods.
- `DevDemoSeeder` — the explicit, opt-in dev/demo path: calls
  `DatabaseSeeder` first (so it is safe to run standalone against a
  completely empty database), then `DemoDataSeeder`, `CommercialSeeder`,
  `OperationsSeeder`, `SupplyChainSeeder` in that order (unchanged demo
  seeder logic — only the orchestration is new).
- `App\Console\Commands\CreatePlatformAdminCommand`
  (`php artisan platform:create-admin`) — the initial-admin strategy.
  Never invents or hardcodes a credential: reads `--email`/`--password` or
  `PLATFORM_ADMIN_EMAIL`/`PLATFORM_ADMIN_PASSWORD`, or prompts
  interactively. Idempotent by email; a bare rerun with no password
  supplied never resets an existing password (Laravel's `secret()` prompt
  is required non-interactively, so a scripted rerun fails safely rather
  than silently touching the password).

Edited (minimal diffs, no logic changes to existing demo-seeder business
logic):
- `DatabaseSeeder` — restructured to the production-safe-only list (see
  order below); doc comment added.
- `CommercialSeeder` — `seedBundlesAndPricing()` visibility changed
  `private` → `public` (doc comment only, no logic change) so
  `CommercialCatalogSeeder` can reuse it.
- `DemoDataSeeder` — class-level doc comment marking it DEV/DEMO ONLY (no
  logic change).

## DatabaseSeeder execution order (production bootstrap)

1. `PermissionSeeder`
2. `ModuleSeeder`
3. `PlatformSuperadminRoleSeeder`
4. `ConfigurationDefaultsSeeder`
5. `AddExternalWorkOrderPrintSectionSeeder`
6. `WorkflowDefaultsSeeder`
7. `RetireMaintenanceRequestNeedInformationSeeder`
8. `AddWorkOrderExternalStatusSeeder`
9. `CorrectWorkOrderExternalTransitionsSeeder`
10. `AddWorkOrderExternalClosedTransitionSeeder`
11. `NotificationDefaultsSeeder`
12. `MasterDataSeeder`
13. `ProductReferenceDataSeeder`
14. `CommercialCatalogSeeder`

Each of items 4-11 follows the pre-existing "platform default" pattern
(`findOrCreateSet(...)`; `if ($set->publishedVersion()) return;`) — every
one of these confirmed either a genuine, still-load-bearing correction on
a fresh database (e.g. `AddWorkOrderExternalClosedTransitionSeeder`'s
EXTERNAL→CLOSED transition, absent from the base `WorkflowDefaultsSeeder`
definitions) or a safe no-op on a fresh database where the base
definitions already include what the correction adds. None were removed:
these are permanent, versioned bootstrap seeders, not one-time historical
data patches — removing any of them would silently regress a
production deployment relative to what `main` already guarantees.

## Known gap — reported, not implemented (out of scope for this initiative)

**Tenant provisioning creates zero default roles.**
`TenantController::store()` (the real Platform API's tenant-creation path)
contains no `Role::`/`RoleAssignment::` reference at all — a new tenant
created via the actual API gets no Tenant Admin/Fleet Manager/etc. roles.
Only `DemoDataSeeder::buildTenant()` creates these roles, and only for its
own two demo tenants (`ALPHA`/`BETA`). No `Tenant` model observer/event
exists either. Per explicit instruction, "Fresh Application Deployment"
and "New Tenant Creation" are different lifecycle events and this
initiative must not silently redesign tenant provisioning — this is
reported as a finding for a future initiative, not fixed here.

## Verification performed this session

- Disposable database (`optifleet_deploy_check`, never touching
  `optifleet_test` or a dev DB) — `migrate:fresh --force` then
  `db:seed --force` three times in a row: all succeeded (exit 0), no
  errors on any run. Representative table row counts identical after
  repeat runs (`roles=1`, `tenants=0`, `users=0`, `product_categories=6`,
  `uoms=5`, and so on) — confirmed via direct SQL, not inferred from
  seeder output alone.
- Application boot verified against the same disposable database:
  `route:list` (633 routes), Access Management/Modules/Product reference/
  Workflow-Configuration dependency checks all PASS via a standalone
  bootstrap script.
- `platform:create-admin` exercised end to end against the disposable
  database: creates the account, assigns the `Platform Superadmin` role,
  password verifies via `Hash::check`; a bare rerun without `--password`
  fails safely (no TTY) rather than silently resetting the password, and
  the original password/role assignment were confirmed unchanged
  afterward.
- New automated tests: `backend/tests/Feature/DeploymentSeederTest.php`
  (6 tests, 21 assertions, all PASS) — `DatabaseSeeder` completes on a
  fresh database and creates no tenant/user; required global reference
  data exists (permissions, modules, `Platform Superadmin` role +
  permissions, Product Categories/UOM/Tool Types/Tire references, 5
  default bundles); idempotency across two runs (identical counts);
  `ProductReferenceDataSeeder`'s Tire reference data is global, never
  tenant-scoped; `CommercialCatalogSeeder` seeds bundles with zero
  tenants; `DevDemoSeeder` still runs standalone against an empty database
  and produces the `ALPHA` demo tenant + Work Order history.
- Full non-Mongo backend regression (`php artisan test`, Mongo migrations/
  tests relocated per the established `ext-mongodb`-unavailable precedent,
  restored afterward with zero diff): see the final report for the actual
  result.

## Deployment documentation updated

`README.md` — added a "Production deployment" section (canonical
`migrate --force` / `db:seed --force` / `storage:link` /
`platform:create-admin` commands, Redis prerequisite, explicit "creates no
tenant/user/operational record" statement); "Running with Docker Compose"
and "Running locally without Docker" now use
`db:seed --class=DevDemoSeeder` to keep their previous full-demo-experience
behavior; the "Seed data" section is now explicitly labeled as
`DevDemoSeeder`-only output.

## Explicitly out of scope (per instruction)

The 4 deferred "Next Improvement" items from the Tenant Portal Alignment
PR (Removed Component Post-Repair Valuation, `ComponentAsset` Integration,
Rim/Tire `Maintainable` behavior, Sparepart `Expiry Tracked` behavior)
remain deferred — not touched by this initiative.
