# OptiFleet — Phase 1 + Phase 2: Commercial SaaS

Multi-tenant Vehicle Maintenance Management SaaS platform. Phase 1 delivered
the application foundation: authentication, multi-tenancy, dynamic RBAC,
organizational data scope, module catalog/dependency/entitlement, capacity
entitlement, organization management (branch/workshop/warehouse), master data
(vehicle category/component group), audit trail, and both the Platform and
Tenant portals. Phase 2 adds the full commercial SaaS lifecycle on top of
that stable baseline, unchanged: Bundle → Pricing → Contract → Subscription →
Entitlement Provisioning → Billing → Invoice → Payment → Verification →
Activation/Renewal/Suspension/Reactivation.

## Architecture

- **Backend**: Laravel 11 (PHP 8.3), modular monolith under
  `backend/app/Domain/{Identity,AccessControl,ProductCatalog,Entitlement,
  Organization,MasterData,Audit,Pricing,Contract,Subscription,Billing,
  Invoice,Payment}`, plus `Platform`/`Tenant` API controllers (Tenant split
  further into operational controllers and a billing-only `Tenant/Account`
  namespace, see below).
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

## Tests

```bash
cd backend
php artisan test
```

93 automated tests: 50 Phase 1 regression tests (authentication, tenant
switching, platform/tenant scope separation, cross-tenant GET/UPDATE/
DEACTIVATE/CREATE denial (IDOR), dynamic RBAC, organizational data scope,
module entitlement, capacity limits, module dependency resolution, master
data CRUD, audit log isolation) plus 43 Phase 2 tests covering bundle
publishing/versioning, pricing resolution and proration math, the full
contract lifecycle (draft → approve → active → amend → renew), billing
generation idempotency, invoice numbering, payment submission/verification/
rejection/resubmission, the dunning pipeline (past-due → grace → suspend →
reactivate), and cross-tenant denial of contracts/invoices/payments.

Tests run against a real PostgreSQL database (`optifleet_test`), not SQLite,
so PostgreSQL-specific behavior (composite FKs, partial unique indexes) is
exercised.

## Known non-blocking limitations

- The `vehicle` capacity resource type has no counting source yet since the
  Vehicle module itself is out of scope for Phase 1/2; the limit can be
  configured but is not yet enforced against real records.
- Docker image builds could not be executed inside this development
  session (see note above) — the configuration itself is complete and
  passed `docker compose config` validation.
- `bcmath` is unavailable in this sandbox's PHP build; the pure-PHP
  `brick/math` library is used instead for all money arithmetic, which is
  precision-equivalent and requires no server configuration change.
