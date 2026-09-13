# R4 — Deployment Readiness and Final Release Gate

This document is the R4 deliverable for the Production Readiness phase
(R1-R4). It covers backup/restore, rollback, migration rehearsal and
legacy-data risk scanning, the environment-variable inventory, seeder
idempotency, deployment order, post-deployment smoke testing, and
monitoring/error-logging. Every claim below marked "tested" or "verified"
was actually executed in this session against a local Postgres 16 /
Redis / PHP 8.4 environment — nothing here is asserted from inspection
alone. No production system, and no tenant other than a disposable
`E2ETEST` tenant created for this purpose, was touched.

## 1. Backup

**Mechanism**: `pg_dump` in custom format (`-F c`), which supports
selective/parallel restore and is the Postgres-recommended format for
anything beyond a toy database.

```bash
pg_dump -h <host> -U <user> -d optifleet -F c -f optifleet_$(date +%Y%m%d_%H%M%S).dump
```

**Tested this session**: a real backup was taken of the local `optifleet`
database (144 tables, including a live tenant with Work Orders, a
Workshop Invoice cycle, and a Tire). Completed in 0.2s at this data
volume; `pg_dump`'s cost scales with data volume, not schema size, so
production timing must be measured against production's own data volume
before relying on this figure.

**Retention / scheduling**: not yet configured in this repository — there
is no backup cron job or managed-backup integration checked in. This is
an infrastructure/ops decision (e.g. managed Postgres automated
snapshots, or a scheduled `pg_dump` + off-site upload) that belongs to
whichever platform hosts production, not to this codebase. **Action for
the release owner**: confirm the hosting platform's backup schedule and
retention window before go-live; this is called out as an open item in
the release decision below.

## 2. Restore — tested end-to-end in an isolated database

```bash
createdb -h <host> -U <user> optifleet_restore_test
pg_restore -h <host> -U <user> -d optifleet_restore_test --no-owner --no-privileges optifleet_<timestamp>.dump
```

**Tested this session**: restored the backup above into a freshly created
`optifleet_restore_test` database (never the original). Verified:
- Identical table count (144 = 144).
- Identical row counts across `tenants`, `users`, `work_orders`,
  `workshop_invoices`, `tires`.
- The exact Workshop Invoice content survived — including a `CANCELLED`
  invoice with its original `external_invoice_number` and status intact
  (proving the restore preserves history, not just row counts).

The restore-test database was dropped immediately after verification —
it never became a long-lived environment.

## 3. Rollback

**Migration rollback — tested this session**: rolled back the most
recent 8 migrations (the entire R1 batch plus one prior) on the isolated
`optifleet_test` database via `DB_DATABASE=optifleet_test php artisan
migrate:rollback --step=8 --force`. Every migration's `down()` ran
cleanly, including the two riskiest operations in this release — dropping
the `workshop_invoices_active_number_unique` partial unique index and
restoring `work_order_external_services`' original 3-value status CHECK
constraint (dropping the extended one first). Re-ran `migrate` forward
afterward and confirmed a clean round-trip: 95/95 migrations `Ran`, 0
`Pending`.

**Application rollback plan** (standard for this stack; not itself
executed against a live deployment in this sandbox, since that requires
the target platform's own deployment tooling):
1. Re-point the load balancer / traffic to the previous release's
   already-running instances (blue/green or canary — whichever this
   platform uses) — this is the fastest rollback and requires no database
   change at all, since this release's migrations are additive (new
   tables/columns/nullable fields) and do not remove or repurpose any
   column the previous release's code reads.
2. Only if the previous code version cannot tolerate the new schema
   as-is (it can — see above): run `php artisan migrate:rollback --step=N`
   for this release's migration batch, using the exact step count from
   this deployment's migration log.
3. Never roll back by restoring a pre-deployment backup unless data
   corruption (not just a bad release) is suspected — a restore loses
   every write since the backup was taken, which a code-only or
   migration-only rollback does not.

## 4. Migration rehearsal and legacy-data preflight scan

**Script**: `backend/database/preflight/legacy_data_preflight.sql` — 12
read-only `SELECT` queries, one per required risk category (duplicate
tire serials, invalid tenant ownership, cross-tenant relationships,
invalid wheel positions, orphaned Work Orders/Memos, inconsistent
inventory, invalid enum values, money/decimal precision risk, missing
reference tread depth, duplicate Workshop Invoice numbers, missing
Partner relationships, and this release's own new-constraint violations).
Every query is documented inline with which specific migration/constraint
it protects against.

**Tested this session**: ran the full script against `optifleet_test`
(after seeding realistic Phase A-G + R1/R2 data via the full PHPUnit
fixture set exercised this session) — all 12 queries executed without a
single SQL error against the real schema (one bug was found and fixed
during this: the inventory-consistency query originally referenced a
nonexistent `inventory_stocks` table; corrected to the real
`warehouse_stocks` table with `quantity_on_hand`/`quantity_reserved`).
Zero rows were returned by any query, because neither `optifleet_test`
nor the local dev database has ever held real production history — this
environment has no legacy data to find.

**This is the critical caveat for the release owner**: this script has
only been proven to *run correctly* against this schema, not proven
"production is clean." It **must** be run against an actual read replica
or backup restore of production data before this release's migrations
are applied there, and any non-empty result remediated (documented
backfill, not silent deletion or alteration) first. Running it is a
release-gate precondition, not a formality.

**Migration rehearsal**: `migrate:fresh` (clean-database case) and
`migrate` after `migrate:rollback --step=8` (upgraded-database case) were
both exercised this session — see Section 3. A rehearsal against an
actual production-sized/production-shaped dataset (not just an empty or
test-fixture database) is a separate, environment-specific step for
whichever platform hosts the pre-production rehearsal environment; this
codebase's part of that rehearsal (the migrations themselves apply
cleanly, forward and backward) is verified.

## 5. Environment variables

Classified from `backend/.env.example` (this repository's own curated
env surface) — no values are reproduced below, only names and
classification.

| Variable | Required? | Secret? | Environment-specific? | Notes |
|---|---|---|---|---|
| `APP_KEY` | Required | **Secret** | No (generate fresh per environment) | Never share across environments; `php artisan key:generate` on first deploy. |
| `APP_ENV` | Required | No | Yes | `production` in production — never `local`/`debug`-flavored in prod. |
| `APP_DEBUG` | Required | No | Yes | **Must be `false` in production** — leaking stack traces is a real exposure risk (see the raw-curl 500 observation in Section 7). |
| `APP_URL` / `FRONTEND_URL` | Required | No | Yes | |
| `SANCTUM_STATEFUL_DOMAINS` | Required | No | Yes | Must list production's actual frontend domain(s). |
| `APP_TIMEZONE` / `APP_LOCALE` / `APP_FALLBACK_LOCALE` / `APP_FAKER_LOCALE` | Optional (has default) | No | No | `APP_FAKER_LOCALE` is dev/test-only — has no effect in production. |
| `APP_MAINTENANCE_DRIVER` | Optional | No | No | |
| `BCRYPT_ROUNDS` | Optional | No | No | Raise for production if hosting hardware allows the added CPU cost. |
| `LOG_CHANNEL` / `LOG_STACK` / `LOG_LEVEL` / `LOG_DEPRECATIONS_CHANNEL` | Required | No | Yes | See Section 8 — `LOG_LEVEL=debug` is a dev default that must not ship to production. |
| `DB_CONNECTION` / `DB_HOST` / `DB_PORT` / `DB_DATABASE` | Required | No | Yes | |
| `DB_USERNAME` / `DB_PASSWORD` | Required | **Secret** | Yes | **Default-prohibited** — must never be committed or left at the `.env.example` placeholder values in any real environment. |
| `MONGO_HOST` / `MONGO_PORT` / `MONGO_DATABASE` / `MONGO_AUTH_SOURCE` | Required only if the analytics module (Phase 6) is enabled for a given deployment | No | Yes | Per repo rules, Mongo-backed tests are explicitly deferred this session — do not newly install/modify Mongo tooling as part of this release. |
| `MONGO_USERNAME` / `MONGO_PASSWORD` / `MONGO_URI` | Optional | **Secret** | Yes | |
| `ANALYTICS_*` (6 vars) | Optional (all have safe code defaults — see `config/analytics.php`) | No | No | Not present in `.env.example`; documented here as a **minor gap** — recommend adding to `.env.example` with their code defaults so ops doesn't have to read `config/analytics.php` to discover them. Not a release blocker. |
| `SESSION_DRIVER` / `SESSION_LIFETIME` / `SESSION_ENCRYPT` / `SESSION_PATH` / `SESSION_DOMAIN` | Required | No | Yes (`SESSION_DOMAIN`) | |
| `BROADCAST_CONNECTION` / `FILESYSTEM_DISK` / `QUEUE_CONNECTION` / `CACHE_STORE` / `CACHE_PREFIX` | Required | No | Yes | `QUEUE_CONNECTION=redis` means a queue worker process is mandatory in production — see Section 6. |
| `MEMCACHED_HOST` | Unused (only relevant if `CACHE_STORE`/`SESSION_DRIVER` is switched to `memcached`) | No | Yes | Safe to leave at default; has no effect with the current `redis` drivers. |
| `REDIS_CLIENT` / `REDIS_HOST` / `REDIS_PORT` | Required | No | Yes | |
| `REDIS_PASSWORD` | Required in any environment where Redis itself requires auth | **Secret** | Yes | **Default-prohibited** in production (`null` is a dev-only convenience). |
| `MAIL_MAILER` / `MAIL_SCHEME` / `MAIL_HOST` / `MAIL_PORT` / `MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME` | Required | No | Yes | `MAIL_MAILER=log` (the `.env.example` default) must not ship to production — no tenant-facing email would ever actually send. |
| `MAIL_USERNAME` / `MAIL_PASSWORD` | Required once a real mailer is configured | **Secret** | Yes | |
| `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | Optional (only if `FILESYSTEM_DISK` is switched to `s3`) | **Secret** | Yes | Currently unused — `FILESYSTEM_DISK=local` — but present in `.env.example` for when attachment storage moves off local disk. |
| `AWS_DEFAULT_REGION` / `AWS_BUCKET` / `AWS_USE_PATH_STYLE_ENDPOINT` | Optional (paired with the above) | No | Yes | |
| `VITE_APP_NAME` | Optional | No | No | Frontend build-time only. |
| `VITE_API_BASE_URL` (frontend `.env`) | Required | No | Yes | Must point at production's real API origin at build time — this is baked into the built JS bundle, not read at runtime. |

**No secret values are reproduced anywhere in this document or in any
file this session wrote to the repository.**

## 6. Permission / module seeder verification — tested this session

- **Idempotency**: `PermissionSeeder` was run twice in immediate
  succession against a freshly migrated `optifleet_test` database. The
  `permissions` table held exactly 275 rows after both the first and
  second run — no duplicates, no errors.
- **New permissions included**: this release's new permission groups
  (`workshop_invoice.*` — 8 permissions; the pre-existing
  `tire_scoring_configuration.manage`/`.publish` — reused, not
  reintroduced) are present in that same seeded set.
- **Does not remove manual assignments**: created a tenant, a
  non-system Role, and assigned it exactly one permission
  (`workshop_invoice.record`) by hand — outside the seeders entirely.
  Re-ran `PermissionSeeder` and `ModuleSeeder` immediately afterward
  (simulating an upgrade deploy on top of live tenant data). The manually
  assigned Role still held exactly that one permission afterward,
  unchanged — the seeders only `updateOrCreate` the platform catalog of
  permissions/modules, they never touch `role_permissions` or
  `role_assignments`.
- **Works on both a clean database and an "upgraded" one**: exercised
  both this session — `migrate:fresh` + seed (clean case) and
  `migrate:rollback` + `migrate` + re-seed on top of already-seeded data
  (upgrade case) — both completed without error.

## 7. Deployment order

1. **Put the application into maintenance mode** (or route new traffic to
   a maintenance page) if the platform's deployment strategy is not
   already zero-downtime blue/green — this release's migrations are
   additive-only, so a brief maintenance window is a safety margin, not
   a strict requirement.
2. **Take a fresh backup** (Section 1) immediately before touching
   anything — this is the rollback safety net regardless of how
   confident the rehearsal was.
3. **Run the legacy-data preflight scan** (Section 4) against production
   (or a same-day replica) and confirm every query returns zero rows, or
   that every non-empty result has a signed-off remediation plan.
4. **Deploy the new backend code** (application files only — do not run
   migrations yet) to all backend instances, kept out of the load
   balancer's rotation until step 6.
5. **Run `composer install --no-dev --optimize-autoloader`** on the new
   backend release if not already baked into the deployment artifact.
6. **Run database migrations** (`php artisan migrate --force`) — exactly
   once, from a single deploy coordinator, never from every instance
   concurrently.
7. **Run the seeders that are safe to re-run in production**
   (`PermissionSeeder`, `ModuleSeeder`; `ConfigurationDefaultsSeeder` only
   if this release adds a new platform-default configuration set — this
   release added the `maintenance_memo` NUMBERING default and the
   `maintenance_memo`/`workshop_invoice` TEMPLATE defaults, so it must
   run this time). **Never** run `DemoDataSeeder`/`CommercialSeeder`/
   `OperationsSeeder`/`SupplyChainSeeder` against production — those are
   demo/fixture seeders only.
8. **Clear and re-warm framework caches**: `php artisan config:cache`,
   `route:cache`, `view:cache` on every backend instance (or bake these
   into the deploy artifact).
9. **Restart the queue worker process(es)** (`QUEUE_CONNECTION=redis` —
   this application depends on a running `php artisan queue:work`
   supervisor process; a code deploy without a worker restart leaves
   old-code workers processing new-schema jobs).
10. **Restart/reload the scheduler's cron entry** if the box running
    `php artisan schedule:run` (invoked once per minute via system cron —
    see `routes/console.php`'s `Schedule::command(...)` entries) changed
    at all; otherwise no action needed, since the scheduler reads code
    fresh on every minute's invocation.
11. **Bring backend instances into the load balancer's rotation** and
    take them out of maintenance mode.
12. **Deploy the frontend build** (`npm run build` output) to its static
    host/CDN, built with `VITE_API_BASE_URL` pointed at production.
13. **Invalidate any CDN cache** for the frontend's `index.html`/asset
    manifest so users receive the new build rather than a stale cached
    one referencing old asset hashes.
14. **Run the post-deployment smoke test** (Section 8) against the live
    production URL before declaring the deployment complete.

## 8. Post-deployment smoke test — tested this session

**Script**: `backend/scripts/smoke_test.sh` — logs in, then checks 10
core/feature endpoints (Vehicles, Work Orders, Products, Warehouses,
Tires, Partners, Purchase Requests, Purchase Orders, **Workshop Invoices
(R1)**, **Tire Scoring Configuration sets (R2)**) all return `200`, and
that an unauthenticated request is correctly rejected with `401`.

**Run this session** against the local dev environment:

```
=== Smoke test result: 12 passed, 0 failed ===
```

All 12 checks passed on the first successful run (an earlier run
surfaced two things worth recording, neither a defect in the
application):
- A raw `curl` request with no `Accept: application/json` header gets a
  Laravel default-exception-handler `500` ("Route [login] not defined")
  instead of a clean `401`, because Laravel treats a header-less request
  as expecting an HTML redirect to a named `login` route that doesn't
  exist in this API-only app. Every real client (the frontend's axios
  instance, this smoke script once corrected, any properly-configured
  monitoring probe) sends `Accept: application/json` and gets the correct
  `401`. **Action for whoever configures external uptime/health-check
  monitoring**: the probe must send that header, or it will misreport a
  routine 401 as a 500 outage.
- The smoke script depends on a pre-provisioned tenant user
  (`SMOKE_EMAIL`/`SMOKE_PASSWORD`) existing in whichever environment it
  targets — it never creates one itself, by design (it must never write
  data as a side effect of a routine health check).

## 9. Monitoring and error logging

**Current state** (verified by reading `config/logging.php` and
`composer.json`): this application uses Laravel's built-in logging
stack only — `stack`/`single`/`daily`/`slack`/`syslog`/`errorlog`
channels, selected via `LOG_CHANNEL`/`LOG_STACK`. **No external
APM/error-tracking service (Sentry, Bugsnag, Datadog, New Relic, etc.)
is integrated in this codebase.** This is a real, honest gap — not
something this session invented a fix for, since adding a specific
vendor's APM integration is a business/tooling decision, not a code
correctness one.

**Owner / response mapping for what exists today**:

| Signal | Where it surfaces today | Owner | Expected response |
|---|---|---|---|
| Unhandled PHP exception (500) | `storage/logs/laravel.log` (or wherever `LOG_CHANNEL` points) | On-call backend engineer | Tail/alert on the log file; no automatic paging exists without an external log-shipping/alerting layer — **this is the top recommended pre-production addition**. |
| `NotificationEngineTest` failure | CI output only | Engineering (accepted, pre-existing — see Accepted Exclusions) | Do not re-triage per this session's explicit instruction; tracked separately. |
| Queue worker crash | Process supervisor (systemd/Supervisor — whichever the host platform uses) exit status | Platform/on-call engineer | Auto-restart via the supervisor config; a sustained crash loop should page, which again requires an external alerting layer this repo does not itself configure. |
| Failed scheduled command (billing, invoice overdue evaluation, analytics ETL, notification escalation) | Laravel's own scheduler failure log entry (each `Schedule::command(...)` call in `routes/console.php` runs `withoutOverlapping()` but has no `->emailOutputOnFailure()`/`->onFailure()` hook configured) | Engineering | **Recommended pre-production addition**: attach `->onFailure()` callbacks (at minimum a Slack channel via the already-available `slack` log channel) to the billing/invoice/subscription/analytics schedule entries — a silent failure of `billing:generate` is a real revenue-impacting risk. |
| Integration outbox stuck in `PENDING` (R1's `IntegrationOutboxEvent`) | No dashboard/alert exists — by design, since no consumer has been built yet (see R1: the outbox is deliberately not wired to any delivery mechanism until an approved accounting integration contract exists) | Whoever eventually owns the accounting integration | Not a current-release concern; noted here so it isn't forgotten once that integration is built. |

**Release-blocking?** No — the application's own code-level error
handling (consistent JSON error responses, `Auditable` audit trail,
transactional integrity throughout R1/R2) is sound and was verified
throughout R1-R3. The absence of an external APM/alerting *product* is
an operational maturity gap common to a pre-first-production-release
system, not a functional defect in this release's code. It is listed
as an **accepted risk** in the release decision below, with a named
follow-up action.

## 10. Release decision

**READY FOR CONTROLLED RELEASE, WITH NAMED OPERATIONAL FOLLOW-UPS** —
one of three possible outcomes for this gate:
`READY_FOR_CONTROLLED_RELEASE` (this one) · `CONDITIONALLY_READY —
BLOCKING_ITEMS_REMAIN` · `NOT_READY`.

**Why READY, not CONDITIONALLY_READY**: every item within this
codebase's own control was verified — migrations forward and backward,
backup and restore, seeder idempotency and non-destructiveness, a
passing smoke test, and (from R1-R3) 86+ passing targeted/regression
tests plus an actual browser-driven walkthrough of every new R1/R2
workflow with zero unresolved defects. Nothing found in this session
required deferring the release itself.

**Named follow-ups that are NOT code blockers but must be tracked**
(owners as identified above):
1. Confirm the hosting platform's automated backup schedule/retention —
   this repository can only prove the backup/restore *mechanism* works,
   not that a schedule exists in whatever production host is chosen.
2. Run the legacy-data preflight script (Section 4) against real
   production data (or same-day replica) before migrating production —
   this session proved the script runs correctly, not that production
   is clean, since no production data exists yet to scan.
3. Set `APP_DEBUG=false`, a real `MAIL_MAILER`, and a production
   `LOG_LEVEL` before go-live (Section 5) — these are `.env.example`
   dev-convenience defaults, not production values.
4. Add an external APM/error-alerting integration and scheduler
   failure hooks (Section 9) — a real gap, but a pre-existing one, not
   introduced by this release, and not something to invent a vendor
   choice for on the codebase's own authority.
5. Provision the two accepted exclusions' separate handling: continue
   treating `NotificationEngineTest` as a known pre-existing failure,
   Mongo-backed tests as separately scheduled, and run the formal
   role-based UAT (checklist provided in the R3 section above) before
   any customer-facing rollout — none of these are this release's to
   resolve.

No merge to `main`, pull request, or production deployment was
performed or is being recommended as an unconditional next step by this
document — per this phase's explicit instructions, that decision belongs
to the repository owner once the follow-ups above are addressed to their
satisfaction.
