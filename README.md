# OptiFleet — Phase 1 + Phase 2 + Phase 3: Core VMS Operations

Multi-tenant Vehicle Maintenance Management SaaS platform. Phase 1 delivered
the application foundation: authentication, multi-tenancy, dynamic RBAC,
organizational data scope, module catalog/dependency/entitlement, capacity
entitlement, organization management (branch/workshop/warehouse), master data
(vehicle category/component group), audit trail, and both the Platform and
Tenant portals. Phase 2 added the full commercial SaaS lifecycle on top of
that baseline, unchanged: Bundle → Pricing → Contract → Subscription →
Entitlement Provisioning → Billing → Invoice → Payment → Verification →
Activation/Renewal/Suspension/Reactivation. Phase 3 adds the operational core
of the VMS itself on top of both, unchanged: Vehicle → Inspection →
Maintenance Planning → Maintenance Request/Breakdown → Work Order →
Diagnosis → Mechanic/Workspace Assignment → Execution → Quality Control →
Vehicle Release → History. Inventory transactions, procurement, tire/
component lifecycle, warranty, and predictive maintenance are explicitly out
of scope for Phase 3 (planned for Phase 4+).

## Architecture

- **Backend**: Laravel 11 (PHP 8.3), modular monolith under
  `backend/app/Domain/{Identity,AccessControl,ProductCatalog,Entitlement,
  Organization,MasterData,Audit,Pricing,Contract,Subscription,Billing,
  Invoice,Payment,Vehicle,Inspection,MaintenancePolicy,MaintenanceRequest,
  Breakdown,WorkOrder,Workshop,QualityControl,VehicleRelease,History}`, plus
  `Platform`/`Tenant` API controllers (Tenant split further into operational
  controllers and a billing-only `Tenant/Account` namespace, see below).
- **Frontend**: React 19 + TypeScript + Vite, React Router, Axios.
- **Database**: PostgreSQL (shared schema, `tenant_id`-scoped tables). All
  money columns are `decimal(14,2)` — never floating point.
- **Cache / Queue**: Redis.
- **Auth**: Laravel Sanctum personal access tokens. The active tenant is
  encoded as a token *ability* (`tenant:<uuid>`) at issuance time — never
  trusted from a client header — so switching tenants issues a fresh token.

### Commercial SaaS lifecycle (Phase 2)

- **Bundles** (`ProductCatalog\Bundle`) group modules into a sellable
  product; publishing a bundle snapshots its module composition into an
  immutable `BundleVersion` so past contracts remain valid even after the
  bundle definition changes.
- **Pricing** (`Pricing\Pricing`/`PricingVersion`) is versioned per
  priceable (module/bundle/add-on/capacity) × billing frequency, with an
  optional tenant-specific override (`TenantCustomPricing`) resolved with
  priority TENANT_CUSTOM > STANDARD. All arithmetic goes through
  `Pricing\Support\Money`, backed by `brick/math` `BigDecimal` — never
  native float math — and rounds half-up to 2 decimals.
- **Contracts** (`Contract\Contract`/`ContractItem`) are the commercial/legal
  agreement: draft → items (pricing frozen at add-time) → submit → approve
  → active, with amendments (add/remove items, reverse-dependency checked)
  and renewals as first-class sub-flows. The server always recalculates
  totals from line items — a contract's `subtotal`/`discount`/`tax`/`total`
  are never accepted from the client (Sections 57–58 of the Phase 2 brief).
- **Subscriptions** (`Subscription\Subscription`) are the technical access
  period a contract provisions; `EntitlementProvisioningService` grants the
  underlying module entitlements in dependency order (topological sort) by
  reusing Phase 1's `TenantModuleEntitlement`/`TenantCapacityLimit` tables
  (extended additively with nullable `contract_id`/`contract_item_id`
  provenance columns) — there is no second, parallel entitlement system.
- **Billing → Invoice → Payment → Verification**: `BillingGenerationService`
  is idempotent (a DB unique constraint on
  `(subscription_id, billing_period_start, billing_period_end)` makes a
  duplicate generation a no-op, not an error) and prorates partial periods
  via `Money::prorate()`. Invoices get a concurrency-safe sequential number
  (`INSERT ... ON CONFLICT DO NOTHING` + `SELECT ... FOR UPDATE`) and a
  server-rendered PDF (Dompdf). Tenants submit payments with a proof file
  (MIME/size-validated, UUID-named, stored on the private `local` disk —
  never web-accessible, never trusting the client-supplied filename);
  platform operators verify or reject, which recalculates the invoice's
  paid/outstanding amount and — once fully paid — (re)activates the
  subscription and restores entitlements, all inside one DB transaction.
- **Suspension**: a daily scheduled dunning pipeline (`billing:generate`,
  `invoices:evaluate-overdue`, `subscriptions:evaluate-grace-period`,
  `contracts:evaluate-expiry`) moves overdue subscriptions through
  PAST_DUE → GRACE_PERIOD → SUSPENDED. A suspended tenant keeps access to
  its billing-only `Account` routes (`/app/account/*` — subscription,
  contract, invoices, payment submission) so it can pay its way out, while
  every operational route is blocked by the `RestrictSuspendedTenant`
  middleware (alias `subscription.access`) with a `403
  SUBSCRIPTION_SUSPENDED` response. The tenant portal shows a persistent
  suspension banner driven by the same `/app/account/subscription` call.

### Core VMS operations (Phase 3)

- **Vehicle** (`Vehicle\Vehicle`) is tenant-scoped with a nullable-safe
  tenant-unique `registration_number`/`vin`/`chassis_number` (Postgres treats
  `NULL` as distinct, so nullable-unique works without extra guard code) and
  a system status (`ACTIVE`/`IN_MAINTENANCE`/`BREAKDOWN`/`OUT_OF_SERVICE`/
  `INACTIVE`/`DISPOSED`). **Assignment** (`VehicleAssignment`, a branch/
  workshop history log) is a separate concept from **Transfer**
  (`VehicleTransfer`, a full `DRAFT→REQUESTED→APPROVED→IN_TRANSIT→
  RECEIVED→COMPLETED` workflow with `REJECTED`/`CANCELLED` side branches) —
  a vehicle's active branch only changes on `COMPLETED`, never mid-transfer.
  Vehicle documents are stored on the private `local` disk and served
  through an authenticated, tenant-scoped download endpoint, never a public
  URL. The vehicle-category ↔ component-group mapping reuses Phase 1's
  `MasterData` tables dynamically — Phase 3 never hardcodes a category or
  group.
- **Inspection** (`Inspection\{InspectionTemplate,Inspection}`) is a
  checklist engine: templates define items with an input type
  (`CHECKBOX`/`PASS_FAIL`/`TEXT`/`NUMBER`/`SELECT`/`PHOTO`); an inspection
  run goes `CREATED→ASSIGNED→STARTED→SUBMITTED`, and `submit()` derives
  `PASSED`/`WARNING`/`FAILED` from the worst severity across its results and
  findings. A `FAILED` (or `WARNING`) inspection can create a Maintenance
  Request in one action.
- **Maintenance Policy & Schedule** (`MaintenancePolicy\*`) models a
  Type/Service-Item/Package/Interval hierarchy with six trigger types
  (`ODOMETER`/`ENGINE_HOUR`/`CALENDAR_DAY`/`MONTH`/`COMBINATION`/
  `CONDITION_BASED`). `MaintenanceDueService` is a pure, stateless
  distance-based evaluator (e.g. odometer 49,800 vs. a 50,000 due point with
  a 500 tolerance → `DUE_SOON`) with full unit coverage for date-based,
  odometer-based, engine-hour-based, combination, overdue, and boundary
  cases. `MaintenanceScheduleService` keeps a single schedule row per
  (vehicle, package) — a DB unique constraint backs this, and a lost race on
  first-time generation falls back to updating the winner's row instead of
  surfacing a raw error to the loser.
- **Maintenance Request & Breakdown** — a request can originate from
  `USER`/`INSPECTION`/`SCHEDULE`/`BREAKDOWN`/`TELEMATICS`/`MECHANIC` and
  moves `DRAFT→SUBMITTED→UNDER_REVIEW→APPROVED→WORK_ORDER_CREATED` (plus
  `REJECTED`/`NEED_INFORMATION`/`CANCELLED`). Breakdowns go
  `REPORTED→VERIFIED→ASSESSED→REPAIR_REQUIRED→WORK_ORDER_CREATED→RESOLVED`
  with severities `MINOR`/`MAJOR`/`IMMOBILIZED`, and can convert directly to
  a Maintenance Request. Both are built as small, swappable transition
  tables — the same pattern Phase 5's configurable workflow engine is meant
  to replace, not extend.
- **Work Order** (`WorkOrder\WorkOrder`) is the central transaction: a
  concurrency-safe built-in numbering service (`WO/OPTIFLEET/{year}/
  {6-digit}`, same `INSERT ... ON CONFLICT DO NOTHING` + `SELECT ... FOR
  UPDATE` sequence as Phase 2's invoice numbering) and a 14-state status
  enum driven exclusively by `WorkOrderTransitionService` — every transition
  row-locks the WO and re-checks status against the *locked* row, never a
  caller's possibly-stale copy, so arbitrary status mutation and lost
  concurrent transitions are both structurally impossible. Diagnosis
  (Complaint→Finding→Diagnosis→Root Cause→Corrective Action), Maintenance
  Jobs, planned parts (planned only — no stock reservation or inventory
  movement; that's Phase 4), and Additional Work
  (`REQUESTED`/`APPROVED`/`REJECTED`) all hang off the WO.
- **Mechanic / Workshop** (`Workshop\{Worker,Workspace,WorkspaceReservation}`)
  — `worker_type` is a resource-planning attribute, never a permission
  source (permissions are still RBAC-only). Mechanic assignment rejects
  cross-tenant and cross-workshop workers at the service layer, not just in
  the UI. The labor timer (`START`/`PAUSE`/`RESUME`/`FINISH`) rejects
  impossible sequences. Workspace reservation overlap is prevented by
  locking the **workspace row itself** first (`SELECT ... FOR UPDATE` on an
  empty result set has nothing to block a second transaction on — locking
  only the reservations table can't stop the very first overlapping pair),
  then checking for an overlap inside that lock.
- **Quality Control & Release** (`QualityControl\*`, `VehicleRelease\*`) —
  QC goes pending→inspection→checklist→finding→pass/fail(-rework); the QC
  approver is blocked from being the same worker who performed the job via
  a `WorkOrderMechanicAssignment` check, enforced by permission, not a
  hardcoded role name. Vehicle release is only possible once its Work Order
  is `COMPLETED`, is refused (not just discouraged) while any other active
  Work Order exists for the same vehicle, and a DB unique constraint on
  `work_order_id` closes the double-release race regardless of any
  preceding application-level check.
- **History & Downtime** (`History\{HistoryService,DowntimeService}`) are
  read-time aggregations over the existing inspection/request/breakdown/WO/
  QC/release tables — there is no separate, duplicated history table to
  keep in sync, and downtime figures (response/waiting/repair/total) are
  derived from timestamps the domain already records, reusable as-is by a
  later analytics phase.

### Multi-tenancy & isolation

Tenant isolation is enforced in three independent layers (defense-in-depth):

1. **Query scope** — models that belong to a tenant (`Branch`, `Workshop`,
   `Warehouse`) use the `BelongsToTenant` trait, which adds a global Eloquent
   scope filtering every query by the current request's tenant.
2. **Explicit ownership check** — every controller action that resolves a
   tenant-owned model via route-model-binding re-verifies
   `$model->tenant_id === $context->tenantId()` before acting on it, and
   returns 404 (not 403) on mismatch so a resource's existence is not leaked
   cross-tenant.
3. **Composite foreign keys** — `workshops`/`warehouses` reference
   `branches`/`workshops` with a composite FK on `(id, tenant_id)`, so the
   database itself rejects a workshop pointing at another tenant's branch.

See `backend/tests/Feature/TenantIsolationTest.php` for the automated
cross-tenant GET/UPDATE/DEACTIVATE/CREATE denial tests.

### Effective access model

A request succeeds only if **all** of the following hold:
Tenant is `ACTIVE` → tenant membership is `active` → user is `active` →
user holds the required permission in that context → the target resource is
within the user's organizational data scope → the resource belongs to the
current tenant. Module entitlement is an additional gate applied to the
routes for modules that are not always-on (`module:CODE` middleware).

## Repository layout

```
backend/    Laravel API (Platform + Tenant portals, same codebase)
frontend/   React SPA (routes under /platform/* and /app/*)
docker/     Dockerfiles + nginx configs
docker-compose.yml
.env.example  Docker Compose environment (copy to .env)
```

## Running with Docker Compose

```bash
cp .env.example .env
docker compose up -d --build
docker compose exec backend php artisan db:seed --force
```

- Frontend: http://localhost:5173
- Backend API: http://localhost:8000/api/v1
- Postgres: localhost:5432 (user/pass/db: `optifleet`)
- Redis: localhost:6379

Migrations run automatically on backend container start
(`docker/backend/entrypoint.sh`). Seeding is a one-time manual step so
restarting the stack never silently re-seeds demo data.

> Note: this sandboxed development session could not execute `docker build`
> because its network egress policy blocks the Docker Hub CDN
> (`production.cloudfront.docker.com` returns 403 for every registry, not
> specific to this project). `docker compose config` validates the compose
> file successfully. The Dockerfiles/compose follow standard, widely-used
> patterns and should build normally in an environment with unrestricted
> registry access (a laptop, or any standard CI runner).

## Running locally without Docker

Requires PHP 8.3+, Composer, Node 20+, PostgreSQL 16, Redis.

```bash
# Backend
cd backend
cp .env.example .env      # then set APP_KEY, DB_*, REDIS_* for your machine
php artisan key:generate
createdb optifleet        # and optifleet_test for running tests
composer install
php artisan migrate
php artisan db:seed

# Frontend
cd ../frontend
cp .env.example .env.local   # VITE_API_BASE_URL=http://127.0.0.1:8000/api/v1
npm install
npm run dev
```

Backend dev server: `php artisan serve` (http://127.0.0.1:8000).

## Seed data

| Account | Email | Password | Scope |
|---|---|---|---|
| Platform Superadmin | `admin@optifleet.test` | `password` | Platform — full access |
| PT Alpha Fleet — Admin | `alpha.admin@optifleet.test` | `password` | Tenant `ALPHA` — TENANT data scope |
| PT Alpha Fleet — Fleet Manager | `alpha.manager@optifleet.test` | `password` | Tenant `ALPHA` — BRANCH data scope (Jakarta only) |
| PT Alpha Fleet — Workshop Manager | `alpha.workshopmanager@optifleet.test` | `password` | Tenant `ALPHA` — WORKSHOP data scope (Jakarta workshop only) |
| PT Beta Logistics — Admin | `beta.admin@optifleet.test` | `password` | Tenant `BETA` — TENANT data scope |
| PT Beta Logistics — Fleet Manager | `beta.manager@optifleet.test` | `password` | Tenant `BETA` — BRANCH data scope |

Tenant `ALPHA` and `BETA` are seeded with **different module entitlements**
and **different capacity limits** (`BETA`'s branch limit is 2, already at 1)
specifically to make cross-tenant differences and capacity enforcement easy
to verify manually. Log in as `alpha.admin@optifleet.test` in one browser
and `beta.admin@optifleet.test` in another (or an incognito window) to see
tenant isolation in action — each only ever sees its own data.

`CommercialSeeder` (Phase 2) additionally seeds four tenants covering the
distinct commercial states: `ALPHA` (active subscription, paid invoice),
`BETA` (past-due subscription, overdue invoice — `beta.admin@optifleet.test`),
`GAMMA` (pending subscription, approved contract not yet activated —
`gamma.admin@optifleet.test`), `DELTA` (suspended subscription, restricted
to billing-only access — `delta.admin@optifleet.test`), all with password
`password`.

`OperationsSeeder` (Phase 3) drives the full VMS lifecycle through real
domain services (not raw inserts) on `ALPHA`: vehicles across both
branches, mechanics/QC workers with skills, workspaces (incl. a QC bay), an
active inspection template, a maintenance package/interval generating a
schedule, and — end to end — an inspection that fails, auto-creates a
Maintenance Request, gets approved, converts to a Work Order, and is driven
through assignment, scheduling, execution (finding → diagnosis → job →
mechanic assignment → labor timer), QC pass, road test, completion, and
vehicle release (ending `CLOSED`/`ACTIVE`). A second Work Order and a
breakdown-in-progress are left in earlier states for list/status variety.
`BETA` is deliberately left with only the `VEHICLE` module (no
`INSPECTION`/`MAINTENANCE`/`WORK_ORDER`) so entitlement denial is visible
without extra setup.

## Tests

```bash
cd backend
php artisan test
```

159 automated tests: 50 Phase 1 regression tests (authentication, tenant
switching, platform/tenant scope separation, cross-tenant GET/UPDATE/
DEACTIVATE/CREATE denial (IDOR), dynamic RBAC, organizational data scope,
module entitlement, capacity limits, module dependency resolution, master
data CRUD, audit log isolation) plus 43 Phase 2 tests covering bundle
publishing/versioning, pricing resolution and proration math, the full
contract lifecycle (draft → approve → active → amend → renew), billing
generation idempotency, invoice numbering, payment submission/verification/
rejection/resubmission, the dunning pipeline (past-due → grace → suspend →
reactivate), and cross-tenant denial of contracts/invoices/payments — plus
66 Phase 3 tests: `MaintenanceDueServiceTest` (15 pure unit tests covering
date-based/odometer-based/engine-hour-based/combination/overdue/boundary due
calculation, no DB), and feature tests covering vehicle CRUD/uniqueness/
branch-scope/assignment/transfer (incl. branch-scoped transfer listing)/
documents, inspection templates and the fail→Maintenance-Request flow,
maintenance policy/schedule generation and duplicate-generation prevention,
maintenance request and breakdown workflows, the full Work Order transition
chain and organization-scope restriction, diagnosis/job/mechanic-assignment/
labor-timer flows (including cross-workshop assignment rejection and
per-worker workload counts), workspace reservation overlap rejection, QC
pass/fail-rework, the self-QC block, workshop-scoped QC inspection listing,
double-release
prevention, and history/downtime aggregation.

Tests run against a real PostgreSQL database (`optifleet_test`), not SQLite,
so PostgreSQL-specific behavior (composite FKs, partial unique indexes) is
exercised.

### Concurrency validation

Every idempotent/racy commercial write path is backed by a DB constraint,
not just an application-level check-then-act guard: billing generation
(`billings_period_unique`), invoice generation from a billing
(`invoices_billing_id_unique`), invoice numbering (`NumberSequenceService`'s
`INSERT ... ON CONFLICT` + `SELECT ... FOR UPDATE`), and row-locked
re-checks (`lockForUpdate()`) on contract approval, subscription
activation, and payment verification/rejection so two concurrent callers
racing the same status transition can't both win it. `php artisan
concurrency:smoke-test` forks real OS worker processes (PHP's built-in dev
server serializes requests closely enough to never race, so this bypasses
it) to race 12 workers generating billing/invoices for the identical period
and 10 workers verifying the same payment, then asserts exactly one billing/
invoice/verified-payment resulted and invoice numbers stayed globally
unique — a manual release-gate tool, not part of the automated suite.

`php artisan concurrency:smoke-test-phase3` does the same for the Phase 3
operational writes explicitly called out as race-prone: 15 workers
generating Work Order numbers for one vehicle (all unique), 10 workers
reserving the identical fresh workspace/time-window (exactly one succeeds —
this is what caught and fixed a real phantom-row race: locking only the
`workspace_reservations` rows can't block a *first* overlapping pair since
there's nothing yet to lock, so `WorkspaceReservationService::reserve()` now
locks the parent `Workspace` row first), 10 workers converting the same
approved Maintenance Request to a Work Order (exactly one WO, no orphaned
loser rows — the loser's `WorkOrder::create()` rolls back inside the same
transaction as the failed `markConverted()` re-check), 10 workers releasing
the same completed Work Order (exactly one `vehicle_releases` row, backed by
its DB unique constraint), and 10 workers submitting the same draft Work
Order (exactly one transition applies). It builds and tears down its own
throwaway tenant, so it never depends on prior seed-run state.

## Known non-blocking limitations

- Docker image builds could not be executed inside this development
  session (see note above) — the configuration itself is complete and
  passed `docker compose config` validation.
- `bcmath` is unavailable in this sandbox's PHP build; the pure-PHP
  `brick/math` library is used instead for all money arithmetic, which is
  precision-equivalent and requires no server configuration change.
- `VehicleMaintenanceProfile` assignment (`MaintenancePackageController::
  assignToVehicle`) uses `firstOrCreate`, which is not itself atomic; a DB
  unique constraint on `(vehicle_id, maintenance_package_id)` prevents any
  duplicate row from ever being committed, but a race loser would currently
  see a raw query-exception response rather than a friendly one. This is an
  admin-only, low-concurrency action (assigning a maintenance policy to a
  vehicle), unlike workspace reservation which is a genuinely contested
  everyday action and was fixed outright — tracked here rather than fixed
  to keep the release gate scoped.
