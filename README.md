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
  Breakdown,WorkOrder,Workshop,QualityControl,VehicleRelease,History,
  Analytics,Intelligence}`, plus
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

### Analytics & Data Warehouse (Phase 6)

PostgreSQL remains the sole transactional source of truth; MongoDB (a
dedicated `mongodb` connection, `config/database.php`) holds only
read-optimized analytical projections built by an ETL layer under
`App\Domain\Analytics`. Nothing in the analytics stack ever performs an
operational mutation (approving a Work Order, issuing stock, etc.) — it
is read/write only within the Analytics domain.

- **ETL orchestration** — a three-tier queued job tree
  (`RunDailyAnalyticsJob → RunTenantAnalyticsJob → RunDatasetEtlJob`) so
  one tenant's or one dataset's failure never blocks another. Retry uses
  Laravel's own queue `tries`/`backoff` (`config/analytics.php`), not a
  custom loop. `analytics:run` / `analytics:backfill` share the exact
  same execution path as the queued jobs (`AnalyticsRunService::
  runDataset()`) via a `--sync` flag, so CLI smoke checks and tests never
  need a running worker.
- **Idempotency** — every `daily_*` Mongo collection has a unique index
  on `(tenant_id, snapshot_date, <dimension>)`; re-running a business
  date upserts the same document in place (observable via
  `AnalyticsUpsertWriter`'s inserted/updated/**skipped** counters — an
  unchanged re-run produces all "skipped", not "updated"). This is also
  what makes late-arriving corrections safe: reprocessing an already-ETL'd
  date (via `analytics:backfill`) simply refreshes that one document.
- **Business date & timezone** — storage stays UTC everywhere (matching
  the existing `APP_TIMEZONE=UTC` convention); a `business_date` is the
  calendar date obtained by converting a UTC instant into the *tenant's*
  own timezone (`tenants.timezone`, additive column, default `UTC`). See
  `App\Domain\Analytics\Support\BusinessDateResolver`.
- **15 analytics datasets**: fleet snapshot, vehicle health (deterministic
  point-deduction score — config-driven weights, explicitly not machine
  learning), maintenance, work order, breakdown, downtime/MTTR/MTBF,
  workshop (incl. workspace utilization), mechanic, inventory, procurement,
  vendor, maintenance cost, tire, component failure, and warranty. Every
  extractor's formula — and any simplification forced by what Phase 1-5
  actually records (e.g. no point-in-time vehicle-status history table, so
  fleet/vehicle-health snapshots reflect status as of ETL execution time)
  — is documented in that extractor's own docblock.
- **KPI engine** (`App\Domain\Analytics\Kpi`) — 18 documented KPIs
  (Fleet Availability, MTTR, MTBF, Workshop/Mechanic Utilization, Vendor
  On-Time Delivery, Maintenance Cost per Vehicle/Km, ...), each a fixed,
  reviewed calculator closure (not a scripting engine) that aggregates the
  daily Mongo collections over a date range rather than re-querying
  PostgreSQL, always returning the numerator/denominator alongside the
  value.
- **Analytical API** — `/api/v1/app/analytics/{overview,fleet,maintenance,
  work-orders,breakdowns,downtime,workshops,mechanics,inventory,
  procurement,vendors,cost,tires,components,warranty}`, one shared
  `AnalyticsDomainController` per Section-41 endpoint list, each enforcing
  the same tenant context/permission/module-entitlement/data-scope as the
  transactional APIs (a branch/workshop/warehouse-scoped user with no
  explicit filter sees their own allowed documents, never the tenant-wide
  rollup). CSV export (`/analytics/export/{domain}`) uses the identical
  filters/scope, streamed and capped rather than loaded into memory.
- **ETL administration** (`/api/v1/platform/analytics/etl/*`,
  `.../analytics/reconciliation`) is platform-scope, not tenant-scope —
  it operates across tenants by accepting an explicit `tenant_id` rather
  than resolving one from context. Every manual run/backfill/retry is
  audited. `AnalyticsReconciliationService` independently re-derives 4
  critical counts from PostgreSQL and compares them to the matching Mongo
  document (`analytics:reconcile` is the CLI form).
- **Frontend** — one generic `AnalyticsDomainPage` renders every domain
  (KPI cards, a dependency-free inline-SVG trend chart, a full numeric
  table with a raw-JSON detail view per row, date-range presets, an
  optional dimension filter, CSV export) plus a freshness banner
  (`data_as_of` / `last_successful_etl_at`) so a stale snapshot is never
  presented as real-time.
- **Not implemented in Phase 6** (by design — Phase 7 scope): failure
  prediction, remaining-useful-life, anomaly detection, prescriptive/
  AI-generated recommendations, automatic predictive Work Order creation.

### Maintenance Intelligence (Phase 7)

An explainable intelligence layer on top of the Phase 6 feature store —
descriptive → diagnostic → predictive → prescriptive, in that order of
maturity, with every stored insight tagged `insight_level` so a health
score, an anomaly, a risk prediction and a recommendation are never
presented as the same kind of claim.

- **Feature store** (`App\Domain\Intelligence\Extractors`) — daily
  `vehicle_daily_features` / `component_daily_features` /
  `tire_daily_features`, built from Phase 1–5 data with the same
  temporal-cutoff discipline as Phase 6 (nothing after the business
  date's boundary ever enters a feature row — covered by a dedicated
  leakage test).
- **ML**: one real trainable target, `vehicle_failure_risk` — logistic
  regression in pure PHP (no external ML library, no Python service;
  data volume at this scale doesn't justify either). Training splits
  strictly by date (earliest 70% train / latest 30% test, never random),
  gates on `DataReadinessAssessmentService` (READY/LIMITED/NOT_READY),
  and only activates a model that clears its own documented
  precision/recall acceptance criteria — never automatically on
  training completion. Every other target (health scores, RUL, repeat
  failure, anomaly, tire/spare-part/demand) is deliberately
  deterministic/statistical, matching Section 12's "a small number of
  real ML targets, not dozens."
- **Deterministic fallback always serves** — `PredictionService` tries
  an ACTIVE tenant model, then an ACTIVE global model, then a
  config-driven rule-based scorer; the response's `source` field
  (`ML_MODEL` vs `RULE_BASED`) is never ambiguous, and `risk_level` is
  always reported alongside a separate `confidence` level.
- **Model registry** (`intelligence_models`) — versioned, never
  overwritten, artifact embedded directly in the document (no
  filesystem/path-based load, which closes model-artifact-path
  injection by construction). Exactly one ACTIVE version per
  (model_code, scope, tenant_id) at a time.
- **Recommendations** (`intelligence_recommendations`) — a simple
  NEW→REVIEWED→ACCEPTED→CONVERTED_TO_ACTION/REJECTED/EXPIRED status
  graph (not the heavyweight Configuration WorkflowEngine — this flow
  is system-driven, not tenant-customizable). Accepting one only ever
  creates a Maintenance Request through the existing
  `MaintenanceRequestService` (`source_type = INTELLIGENCE`, linked via
  `source_recommendation_id`/`source_prediction_id`) — Phase 7 never
  closes a Work Order, issues stock, or takes any operational action
  directly.
- **Outcome feedback** — `OutcomeFeedbackService` reuses the exact
  `LabelBuilder` a target's training pipeline uses to check matured
  predictions against ground truth, feeding `ModelMonitoringService`
  (prediction volume, confidence, precision/recall over time) and
  `DriftAssessmentService` (fleet feature-distribution drift,
  STABLE/WATCH/DRIFTED — a signal to review, never an auto-retrigger).
- **APIs**: `/api/v1/app/intelligence/{overview,vehicles,components,
  tires,inventory,predictions,recommendations}` (tenant, module
  `MAINTENANCE_INTELLIGENCE` + per-route permission + branch data-scope
  via `EntityScopeResolver`, since predictions carry `vehicle_id` rather
  than `branch_id` directly) and `/api/v1/platform/intelligence/
  {models,training,monitoring}` (platform, audited).
- **Not implemented in Phase 7** (by design — Phase 8+ scope, per the
  spec's own "no ML" list): failure-prediction for targets beyond
  vehicle_failure_risk, survival-analysis RUL, generative/LLM-based
  recommendations, automatic predictive Work Order creation.

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
docker compose exec backend php artisan db:seed --class=DevDemoSeeder --force
```

The backend image runs every PHP process as `www-data` — the owner of `storage/` — so
`docker compose exec backend php artisan …` already runs as the right user. Do not add
`--user root` to artisan commands: files a root process writes under `storage/app/private`
(e.g. seeded demo documents) are not accessible to the web process, and uploads then fail with
"Unable to create a directory …". Uploaded documents live in the `optifleet_storage` volume
(`storage/app`). If an older deployment left root-owned files behind, the backend refuses to
start and prints the fix: `docker compose run --rm --user root backend true` (repairs ownership
once, then continues as `www-data`). Never use `chmod -R 777`.

- Frontend: http://localhost:5173
- Backend API: http://localhost:8000/api/v1
- Postgres: localhost:5432 (user/pass/db: `optifleet`)
- Redis: localhost:6379
- MongoDB (Phase 6 analytics projection only): localhost:27017

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

Requires PHP 8.3+ with the `mongodb` extension (Phase 6), Composer,
Node 20+, PostgreSQL 16, Redis, MongoDB 7.

```bash
# Backend
cd backend
cp .env.example .env      # then set APP_KEY, DB_*, REDIS_*, MONGO_* for your machine
php artisan key:generate
createdb optifleet        # and optifleet_test for running tests
composer install
php artisan migrate       # also provisions the Mongo analytics collections/indexes
php artisan migrate:fresh --seed            # with APP_ENV=local: schema + full demo data in one command — see "Seed data" below
# (equivalent on an existing schema: php artisan db:seed --class=DevDemoSeeder)

# Frontend
cd ../frontend
cp .env.example .env.local   # VITE_API_BASE_URL=http://127.0.0.1:8000/api/v1
npm install
npm run dev
```

Backend dev server: `php artisan serve` (http://127.0.0.1:8000).

## Production deployment

A fresh production environment runs the bootstrap-only path — no demo
tenant, no demo user, no operational history. It is idempotent and safe
to re-run:

```bash
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link
php artisan platform:create-admin     # provisions the first platform login; see below
```

`php artisan db:seed` (i.e. `DatabaseSeeder`, the default target of `db:seed`
with no `--class`) seeds only global, idempotent system/reference data:
Access Management (permissions, the `Platform Superadmin` role), Modules,
Configuration/Workflow/Notification platform defaults, and reference/master
data (Vehicle Category, Component Group, Product Category, UOM, Tire/Tool/
Equipment reference data, Storage Requirement, and the default commercial
bundle/pricing catalog). It creates **no tenant, no user, and no
operational record** — running it twice in a row never duplicates data.

`php artisan platform:create-admin` provisions the first platform-level
administrator account. It never hardcodes a credential: pass
`--email`/`--password`, set `PLATFORM_ADMIN_EMAIL`/`PLATFORM_ADMIN_PASSWORD`
for a scripted/unattended deploy, or omit both for an interactive prompt.
Re-running it against an existing admin email updates that user's role
assignment and only changes the password if one was explicitly supplied
that run.

Production requires `CACHE_STORE`/`SESSION_DRIVER=redis` (per `.env`) to
have a running Redis instance reachable before `db:seed` — the
Configuration platform-default seeder depends on the cache layer.

Demo/development data (`DemoDataSeeder`'s two fully-populated demo tenants
and hardcoded local-dev login, `CommercialSeeder`'s subscription-scenario
tenants, `OperationsSeeder`'s Work Order history, `SupplyChainSeeder`'s
product/inventory/tire supply chain, `DemoDatasetSeeder`'s list completion)
is opt-in and must never run against production. `DatabaseSeeder` adds it
only when `APP_ENV=local`, or when `SEED_DEMO_DATA=true` is set explicitly
(`SEED_DEMO_DATA=false` turns it off even locally); `php artisan db:seed
--class=DevDemoSeeder` always seeds it.

## Seed data

The table and scenarios below are produced by `DevDemoSeeder` (dev/demo
only, opt-in — see "Production deployment" above for what the default
`db:seed` actually creates).

**One command (local):** with `APP_ENV=local` in `backend/.env`,

```bash
cd backend
php artisan migrate:fresh --seed
```

drops and rebuilds the schema and seeds bootstrap + demo data. Every demo
seeder is idempotent: running `php artisan db:seed` again on the same
database creates no duplicates (only audit-log entries are added).

All demo accounts use the password `password` (local demo credentials only —
never reuse them in a shared or production environment).

| Role | Email | Password | Tenant / data scope |
|---|---|---|---|
| Platform Superadmin | `admin@optifleet.test` | `password` | Platform — full access |
| Admin Tenant | `alpha.admin@optifleet.test` | `password` | `ALPHA` — whole tenant |
| Fleet Manager | `alpha.manager@optifleet.test` | `password` | `ALPHA` — branch `ALPHA-JKT` (Jakarta) |
| Workshop Manager | `alpha.workshopmanager@optifleet.test` | `password` | `ALPHA` — workshop `ALPHA-JKT-WS1` |
| Warehouse Manager | `alpha.warehousemanager@optifleet.test` | `password` | `ALPHA` — warehouse `ALPHA-BDG-WH1` |
| Procurement | `alpha.procurement@optifleet.test` | `password` | `ALPHA` — whole tenant |
| Mechanic | `alpha.mechanic@optifleet.test` | `password` | `ALPHA` — workshop `ALPHA-JKT-WS1` |
| QC | `alpha.qc@optifleet.test` | `password` | `ALPHA` — workshop `ALPHA-JKT-WS1` |
| Admin Branch Tenant | `alpha.branchadmin@optifleet.test` | `password` | `ALPHA` — branch `ALPHA-BDG` (Bandung) |
| PT Beta Logistics — Admin | `beta.admin@optifleet.test` | `password` | `BETA` — whole tenant |
| PT Beta Logistics — Fleet Manager | `beta.manager@optifleet.test` | `password` | `BETA` — branch scope |

Roles are ordinary tenant roles (permission sets); what each account can do
comes from its role's permissions and its data scope, never from the role
name. Demo tire serial numbers are 20-character codes
(`XXXX-XXXX-XXXX-XXXXX`, generated deterministically by `DemoSerial`).

`DemoDatasetSeeder` brings the `ALPHA` lists to at least 5 representative
records each (branches, workshops, warehouses, vendors, vehicles, Wheels
Configurations + mappings, installed and in-stock tires, Tire Operations in
every status, part requests, purchase requests → RFQ → PO → goods receipt,
workers, workspaces, inspection templates, inspections, maintenance
packages / schedules, maintenance requests, breakdowns, tire specification
masters, used tire inspection rule profiles (demo values)). Used Tire
Management has a REMOVED tire awaiting inspection, HOLD / REUSE / SCRAP tires
from approved inspections, a REUSE tire in Semarang's used tire quantity
(Warehouse Stock → Used Tires), and a bus Replacement whose REUSE tire is
issued through a USED Part Request line and shows its usage-restriction
warning. Documented exceptions, kept at one reference record because each
is the end product of a full Work Order lifecycle or is a per-tenant
singleton: the commercial contract / subscription / billing / invoice /
payment (one per tenant by design), QC inspection, road test, vehicle
release, warranty + claim, stock transfer and component asset (the single
end-to-end reference flows of `OperationsSeeder` / `SupplyChainSeeder`), the
legacy tire rotation / removal events (superseded by Tire Operations), and
global system reference data (component taxonomy, UOM, vehicle categories,
tool / equipment types, configuration sets and workflows — seeded once,
platform-wide, not per tenant).

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

Phase 6's `ANALYTICS` module is **not** granted by the seeders by default
(it depends only on `HISTORY`, but analytics dashboards are meaningless
without at least one ETL run). To see the Analytics section:

```bash
php artisan tinker --execute="
  \$t = App\Domain\Identity\Models\Tenant::where('code','ALPHA')->first();
  \$m = App\Domain\ProductCatalog\Models\Module::where('code','ANALYTICS')->first();
  app(App\Domain\Entitlement\Services\EntitlementService::class)->grant(\$t->id, \$m);
"
php artisan analytics:run --tenant=<ALPHA tenant id> --sync
```

Phase 7's `MAINTENANCE_INTELLIGENCE` module is likewise **not** granted by
default (depends on `VEHICLE`/`MAINTENANCE`/`HISTORY`/`ANALYTICS`). To see
the Maintenance Intelligence section, grant it the same way and then run the
pipeline in order (each stage reads the previous one's output for the same
business date):

```bash
php artisan tinker --execute="
  \$t = App\Domain\Identity\Models\Tenant::where('code','ALPHA')->first();
  \$m = App\Domain\ProductCatalog\Models\Module::where('code','MAINTENANCE_INTELLIGENCE')->first();
  app(App\Domain\Entitlement\Services\EntitlementService::class)->grant(\$t->id, \$m);
"
php artisan intelligence:generate-features --tenant=<ALPHA tenant id> --sync
php artisan intelligence:predict --tenant=<ALPHA tenant id> --sync
php artisan intelligence:health --tenant=<ALPHA tenant id> --sync
php artisan intelligence:diagnostics --tenant=<ALPHA tenant id> --sync
php artisan intelligence:recommendations --tenant=<ALPHA tenant id>
```

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

### Phase 6 — Analytics & Data Warehouse tests

42 additional feature tests under `tests/Feature/Analytics/`, run against a
real MongoDB database (`MONGO_DATABASE=optifleet_analytics_test`, set in
`phpunit.xml`) alongside the same PostgreSQL test database — not a mock:

- `AnalyticsEtlFoundationTest` — Mongo connectivity, ETL run tracking
  metadata, idempotent re-run, retry-after-failure with an incrementing
  `retry_count`, partial-failure status, one tenant's dataset failure not
  affecting another's, cross-tenant Mongo document isolation.
- `FleetMaintenanceWorkOrderBreakdownAnalyticsTest`,
  `WorkshopMechanicDowntimeAnalyticsTest`,
  `InventoryProcurementVendorAnalyticsTest`,
  `CostTireComponentWarrantyAnalyticsTest` — the actual formula for every
  one of the 15 datasets (e.g. MTTR excludes CANCELLED Work Orders even
  though they carry timestamps; workspace utilization correctly clips a
  reservation spanning midnight to only the portion inside the business
  day; a blocked workspace is excluded from available capacity).
- `KpiCatalogTest` — a KPI reads the latest snapshot in range for
  point-in-time figures, and (this is the one that would silently break
  if MongoDB's `$sum` stopped supporting a dotted field path like
  `"workspace_utilization.occupied_minutes"`) correctly sums a nested
  field across multiple days.
- `AnalyticsBackfillAndScopeTest` — the `analytics:backfill` CLI actually
  reprocesses every date in a range; a late-arriving correction
  (Work Order data changed after its business date's ETL already ran) is
  picked up by reprocessing that original date, in place, not as a
  duplicate; extraction for one business date provably does not read the
  next day's source rows (incremental, not a full scan); workshop- and
  warehouse-scoped users on the analytics API see only their own
  dimension's documents (branch scope is covered in `AnalyticsApiTest`).
- `AnalyticsApiTest` — permission denial, module-entitlement denial,
  data-scope-restricted dimension access (403 on a value outside scope,
  own-scope-only documents with no explicit filter), CSV export using the
  same scope restriction, platform-only ETL admin permission + audit
  logging, malformed date input rejected with 422 (not a 500),
  reconciliation matching real counts.

### Phase 7 — Maintenance Intelligence tests

56 additional feature tests under `tests/Feature/Intelligence/`, run
against the same real PostgreSQL + MongoDB test databases:

- `FeaturePipelineTest` — feature extraction fields and explicit-null
  handling for missing data, idempotent re-run, tenant isolation, and a
  release-critical temporal-cutoff test proving a breakdown reported
  *after* a feature date never influences that day's feature row.
- `ModelRegistryTest` / `DataReadinessTest` — version increment (never
  overwritten), activation blocked by an unmet acceptance criterion,
  activating a new version retiring the prior ACTIVE one (exactly one
  ACTIVE at a time), a DRAFT model rejected for direct activation,
  per-tenant training isolation, and a label whose 30-day horizon hasn't
  elapsed yet correctly excluded from the training sample (not coerced
  into a negative).
- `TrainingPipelineTest` — insufficient data fails gracefully with no
  model created; a real training run on a small synthetic fixture reaches
  EVALUATED with computed precision/recall/ROC-AUC; a model failing its
  own acceptance criteria cannot be activated; training never pools data
  across tenants.
- `PredictionPipelineTest` — falls back to the deterministic scorer with
  no ACTIVE model (labeled `RULE_BASED`, never presented as ML), uses an
  ACTIVE model when one exists (labeled `ML_MODEL`), idempotent re-run
  for one business date, prediction history preserved across distinct
  dates, freshness reflecting source-data age, and an explanation
  independently traced back to the exact feature value that produced it.
- `HealthScoreTest`, `DiagnosticsTest` — a degraded vehicle scoring lower
  than a healthy one with an explainable subscore breakdown; RUL always
  returned as a low/high range labeled `INTERVAL_BASED`, never a fake
  precise number; repeat-failure detection tagged `DIAGNOSTIC`, not
  `PREDICTIVE`; all of it idempotent and tenant-isolated.
- `InventoryAndTireIntelligenceTest` — consumption ranking/trend, a
  demand forecast that flags shortage risk while independently verified
  to never create a Purchase Order, tire product performance from a real
  installation fixture.
- `RecommendationTest`, `OutcomeFeedbackTest` — a high-risk prediction
  generating exactly one recommendation (idempotent per prediction, not
  per day), an invalid review-status transition rejected, an accepted
  recommendation converting to a Maintenance Request through the
  existing service with the linkage columns populated, matured
  predictions evaluated against ground truth exactly once.
- `IntelligenceApiTest`, `IntelligenceAdminApiTest`, `MonitoringAndDriftTest`
  — permission/module-entitlement denial, branch-scoped access denied for
  a vehicle outside scope, a targeted regression test for a real bug this
  phase found and fixed (an overview count that forgot to apply branch
  scope to component/tire predictions, which don't carry `branch_id`
  directly), cross-tenant isolation, platform-only model activation with
  audit logging, and model monitoring/drift computed only from each
  tenant's own data.

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

**Phase 6:**

- Fleet snapshot and vehicle-health status distributions reflect vehicle
  status *as of ETL execution time* — Phase 1-5 has no point-in-time
  vehicle-status-history table, so backfilling a past business date
  re-labels the current distribution under that date rather than
  reconstructing history that was never captured. Every affected
  extractor documents this in its own docblock rather than silently
  presenting it as historically accurate.
- `labor_cost` and `external_service_cost` in Maintenance Cost Analytics
  are explicitly `null` — Phase 1-5 records labor *time*
  (`work_order_labor_logs.actual_minutes`) but no per-worker hourly rate,
  and no external-service invoice amount distinct from parts procurement,
  so neither can be derived without fabricating a rate (Section 39: only
  expose what the source data supports).
- Mechanic utilization is measured against a configured standard shift
  length (`ANALYTICS_MECHANIC_SHIFT_MINUTES`, default 480), not each
  mechanic's actual scheduled hours — no shift/roster table exists to
  measure real availability.
- Export is CSV only; no XLSX writer library exists in this project's
  dependencies, and Section 47 makes XLSX conditional on one already
  being present.
- Drill-down (Section 46: overview → dimension → operational resource) is
  not implemented in the UI beyond showing raw IDs in the metrics table's
  detail view — clicking through to the underlying PostgreSQL operational
  record (e.g. a specific Work Order from a breakdown row) would need a
  small amount of additional routing, not new backend capability (every
  analytics document already carries the operational IDs, and the
  existing operational APIs already enforce their own authorization).
- No caching layer sits in front of the analytics API; every request re-
  reads MongoDB directly. At the data volumes this phase's own KPI/
  dashboard queries are designed for (aggregating a bounded date range of
  daily documents, not scanning PostgreSQL), this was not a bottleneck in
  testing — added if a real production load profile shows otherwise.
- Performance validation (Section 62) was structural, not a
  representative-scale load test: confirmed indexes exist and are used,
  and that dashboard queries read one pre-aggregated collection instead
  of joining several PostgreSQL tables (e.g. rework rate would otherwise
  join `work_orders` + `audit_logs` on every page view). The seeded demo
  dataset is too small for a wall-clock benchmark to be meaningful either
  way.

**Phase 7:**

- Only `vehicle_failure_risk` has a real trainable ML path (logistic
  regression). Every other target (component/breakdown/repeat-failure/
  tire-replacement/maintenance-overdue risk beyond the built-in
  deterministic scorers) is intentionally rule-based only, per Section
  12's "a small number of real ML targets, not dozens" — the
  registry/readiness/prediction pipeline is fully generic, so adding a
  trained model for another target needs no redesign, only a
  `RiskScorer`/`LabelBuilder` pair and a config entry.
- On this project's own synthetic demo/test fixtures, a real training
  run correctly stays *below* its activation bar (precision/recall too
  low for the acceptance gate) rather than being force-activated — this
  is the intended safe behavior when data volume is limited, not a
  defect, and the deterministic fallback is what actually serves
  `vehicle_failure_risk` predictions on the seeded demo tenant today.
- Vehicle/component/tire RUL is `INTERVAL_BASED` (derived from the
  vehicle's own maintenance-schedule next-due fields, or an assumed
  typical tire service life) — Phase 1-5 has no component/tire service-
  interval table, so a true ML-estimated RUL is architecturally
  supported (the same `rul` response shape) but not populated for
  those entity types yet.
- Anomaly detection is fleet-wide cross-sectional (this business day's
  vehicles compared to each other), not a per-vehicle time series — the
  operational history in the seeded/test fixtures is too short for a
  per-vehicle series to be statistically meaningful yet; the
  `AnomalyDetectionService` interface does not change when richer
  history exists.
- No dedicated CSV/XLSX export endpoint for Intelligence data (unlike
  Phase 6's analytics export) — the spec's own Phase 7 API list does not
  include one; the list/detail endpoints already provide full table
  access to every value shown.
- No caching layer, for the same reasoning as Phase 6's analytics API —
  added only if a real production load profile shows it's needed.
