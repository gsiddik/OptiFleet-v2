# OptiFleet — Phase 1: SaaS Foundation

Multi-tenant Vehicle Maintenance Management SaaS platform. This repository
contains Phase 1 only: application foundation, authentication, multi-tenancy,
dynamic RBAC, organizational data scope, module catalog/dependency/entitlement,
capacity entitlement, organization management (branch/workshop/warehouse),
master data (vehicle category/component group), audit trail, and both the
Platform and Tenant portals.

## Architecture

- **Backend**: Laravel 11 (PHP 8.3), modular monolith under
  `backend/app/Domain/{Identity,AccessControl,ProductCatalog,Entitlement,
  Organization,MasterData,Audit}`, plus `Platform`/`Tenant` API controllers.
- **Frontend**: React 19 + TypeScript + Vite, React Router, Axios.
- **Database**: PostgreSQL (shared schema, `tenant_id`-scoped tables).
- **Cache / Queue**: Redis.
- **Auth**: Laravel Sanctum personal access tokens. The active tenant is
  encoded as a token *ability* (`tenant:<uuid>`) at issuance time — never
  trusted from a client header — so switching tenants issues a fresh token.

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

## Tests

```bash
cd backend
php artisan test
```

50 automated tests cover: authentication, tenant switching, platform/tenant
scope separation, cross-tenant GET/UPDATE/DEACTIVATE/CREATE denial (IDOR),
dynamic RBAC (grant/revoke), organizational data scope (branch/workshop/
warehouse cascade), module entitlement (grant/revoke, dependency
enforcement), capacity limits, module dependency resolution (direct,
transitive, reverse, circular rejection), vehicle category / component
group CRUD, system-master protection, the many-to-many mapping in both
directions, and audit log creation + isolation.

Tests run against a real PostgreSQL database (`optifleet_test`), not SQLite,
so PostgreSQL-specific behavior (composite FKs, partial unique indexes) is
exercised.

## Known non-blocking limitations (Phase 1 scope)

- Pricing, contracts, billing, invoices, payments — intentionally out of
  scope per the Phase 1 brief.
- The `vehicle` capacity resource type has no counting source yet since the
  Vehicle module itself is a Phase 2 concern; the limit can be configured
  but is not yet enforced against real records.
- Docker image builds could not be executed inside this development
  session (see note above) — the configuration itself is complete and
  passed `docker compose config` validation.
