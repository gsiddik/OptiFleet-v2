# Functional Testing Seeder

## Purpose

Generates a deterministic, `[TEST]`-marked dataset for manually and
functionally testing OptiFleet's current business flows — Dynamic Product
(all six Item Types), Workshop Scheduler, Internal Work Order lifecycle,
and External Work Order / WAL / Invoice / Settlement — without hand-creating
prerequisite data first. Every transactional record is created through the
same domain services the application itself uses (`WorkOrderService`,
`ExternalWorkOrderService`, `WorkOrderExternalInvoiceService`,
`ProductSpecificationService`, `InventoryService`, procurement services),
never by inserting rows directly, so seeded state is exactly what the same
actions via the API would produce.

## Supported Environment

**Local / development / testing only.** The seeder refuses to run when
`app()->environment('production')` is true and prints an error instead —
this is enforced in code (`FunctionalTestingSeeder::run()`), not left to
developer discipline. This dataset must never be used as production
bootstrap data: it creates named accounts with the well-known password
`password`.

## Command

```bash
php artisan db:seed --class=Database\\Seeders\\FunctionalTestingSeeder
```

Runnable standalone against an already-migrated database — it does not
require `migrate:fresh` and does not depend on `DemoDataSeeder`,
`CommercialSeeder`, `OperationsSeeder`, or `SupplyChainSeeder` having run.
It seeds its own platform prerequisites (permissions, modules, numbering
configurations, the Work Order workflow graph) idempotently at the top of
`run()`, then builds its own dedicated tenant. **Safe to run repeatedly**:
every sub-seeder looks records up by a stable business key (tenant `code`,
user `email`, product `sku`, vehicle `registration_number`, a Work Order's
`[FT-...]` complaint prefix) before creating anything, so a rerun updates
in place (refreshing relative scheduling dates) instead of duplicating.

MongoDB is out of scope and never touched by this seeder.

## Test Accounts

All passwords: `password` (hashed through Laravel's normal `Hash::make`,
never stored in plaintext). Tenant: **`[TEST] Functional Test Tenant`**
(code `FTEST`).

| Role | Login | Purpose |
| ---- | ----- | ------- |
| Tenant Admin | `ft.admin@optifleet.test` | Full tenant access |
| Fleet Manager | `ft.fleetmanager@optifleet.test` | Day-to-day operational access, branch-scoped |
| Workshop Manager | `ft.workshopmanager@optifleet.test` | Work Order/QC/External WO approvals, workshop-scoped |
| Warehouse Manager | `ft.warehousemanager@optifleet.test` | Inventory/procurement, warehouse-scoped |
| Auditor | `ft.auditor@optifleet.test` | Read-only across the tenant |

These are the actual canonical Role names this codebase defines (confirmed
against `PermissionSeeder`/`DemoDataSeeder`) — there is no "Admin",
"Operational", "Lead Mechanic", "Mechanic", or "Warehouse" Role in the
system. Mechanics are `Worker` records, not login accounts (see below),
matching the application's own existing seeding pattern.

### Workers (no login — assigned to Work Orders, not authenticated)

| Employee Code | Name | Worker Type | Status |
| --- | --- | --- | --- |
| FTEST-MEC-01 | [TEST] Lead Mechanic Budi | LEAD_MECHANIC | ACTIVE |
| FTEST-MEC-02 | [TEST] Mechanic Andi | MECHANIC | ACTIVE |
| FTEST-MEC-03 | [TEST] Mechanic Citra | MECHANIC | ACTIVE |
| FTEST-QC-01 | [TEST] QC Inspector Dewi | QC | ACTIVE |
| FTEST-MEC-04 | [TEST] Inactive Mechanic Eko | MECHANIC | **INACTIVE** (historical reference only) |

## Scenario Catalog

### Master data

- Storage hierarchy: `[TEST] Main Warehouse` → Zone A/B → Racks → Bins (Section 17's example, exactly).
- Product Categories, UOMs (incl. Liter/Drum for a conversion scenario), Tool/Equipment Types, a Storage Requirement, and the 5 Tire reference tables — all tenant-scoped and `[TEST]`-marked.
- Vehicles: `B 1001 TST`…`B 1005 TST` (2 cars, 2 trucks, +1 dedicated car for the full-release scenario). Includes `purchase_month`/`purchase_year`.
- Workspaces: `[TEST] Service Bay 1` (Car), `[TEST] Service Bay 2` (Truck & Bus — multi-select category test), `[TEST] QC Bay`.

### Products — all six Item Types (21 products, SKU-prefixed `TEST-`)

| SKU pattern | Item Type | Variations covered |
| --- | --- | --- |
| `TEST-SP-00x` | Sparepart | Standard, Serialized, Critical Part, Warranty, Multi-vehicle compatibility |
| `TEST-CS-00x` | Consumable | Standard, UOM conversion (Drum→Liter), Expiry tracking, Hazardous+Storage Requirement, Batch tracked |
| `TEST-RIM-00x` | Rim | Standard, Serialized + vehicle-compatible |
| `TEST-TIRE-CAR-001` / `TEST-TIRE-TRUCK-001` | Tire | Car (derived tire size/load/speed) and Truck & Bus (+ dual load index, ply rating, TRA code/star rating) |
| `TEST-TOOL-00x` | Tool | Standard, Serialized, Checkout required, Calibration required, Maintenance required |
| `TEST-EQP-00x` | Equipment | Standard, and full-compliance (maintenance+inspection+calibration+certification) |

All created through `ProductSpecificationService` + `DocumentNumberingService`
(real Item Code generation, real conditional-field validation, real Tire
derived-value computation) — never a raw `Product::create()`.

### Internal Work Order (`FT-WO-*`)

| Scenario ID | Status | Purpose |
| --- | --- | --- |
| `FT-WO-DRAFT` | DRAFT | Simplest state |
| `FT-WO-SCHEDULED` | SCHEDULED | Today+1, Bay 1 |
| `FT-WO-SCHEDULED-PLUS2` / `-PLUS3` | SCHEDULED | Today+2/+3, 7-day Scheduler window |
| `FT-WO-SCHEDULED-NEXTWEEK` | SCHEDULED | Today+7, next-week navigation |
| `FT-WO-IN_PROGRESS` | IN_PROGRESS | Mid-execution: finding, diagnosis, job, mechanic assigned, labor running |
| `FT-WO-ON_HOLD` | ON_HOLD | Paused mid-execution |
| `FT-WO-WAITING_PART` | WAITING_PART | Blocked on an unstocked part |
| `FT-WO-QC_PENDING` | QC_PENDING | Job complete, awaiting QC |
| `FT-WO-COMPLETED` | COMPLETED | Full flow incl. part reserve→issue→consume, QC pass, road test, Vehicle Release (yesterday) |
| `FT-WO-COMPLETED-PREVWEEK` | COMPLETED | Today-7, previous-week navigation |
| `FT-WO-CLOSED` | CLOSED | Full flow + close (2 days ago) — verify Scheduler excludes it |

### External Work Order / WAL / Invoice / Settlement (`FT-EWO-*`, Section 33)

Findings are the basis for every scenario — Jobs are never used, per the
confirmed rule.

| Scenario ID | State reached | Tests |
| --- | --- | --- |
| `FT-EWO-PRE-FINALIZE` (E1) | Draft, External mode, Finding recorded, not finalized | "Ready for external processing" |
| `FT-EWO-PRE-WAL` (E2) | `EXTERNAL`, Invoice `NEW_EXTERNAL_WO` | `Revise` (External → Draft) still allowed |
| `FT-EWO-WAL-DELIVERED` (E3) | WAL generated + delivered, Invoice `DELIVERED` | `Revise` now blocked |
| `FT-EWO-INVOICE-BILLED` (E4) | Acknowledged, vendor invoice recorded, Invoice `BILLED` | "Workshop Invoice" open for payment |
| `FT-EWO-SETTLED` (E5) | Exact-match payment, Invoice `PAID`, Work Order auto-`CLOSED` | Settlement + Closed-WO coverage |

`FT-WO-MAINTENANCE-MEMO`: a bonus scenario covering the genuinely distinct
pre-existing `WorkshopInvoice` feature (Partner "Maintenance Memo" billing
on an internal Work Order) — record → pay, exact-match only.

## Important Restrictions

- Do not run this seeder against a production database — it is refused automatically, but do not attempt to bypass that guard.
- This dataset is for manual/functional testing only. It is not a substitute for `DemoDataSeeder`/`CommercialSeeder`/etc., which remain the production-realistic demo bootstrap data, and this seeder does not touch or depend on them.
- Do not treat the `[TEST]`/`TEST-`/`FT-...` markers as a namespacing convention to build on — they exist purely so a tester can immediately recognize seeded records.

## Automated Coverage

`tests/Feature/FunctionalTestingSeederTest.php` runs the seeder twice in
one test to prove idempotency, and asserts: referential integrity (a
product's storage bin + spec row), tenant isolation (every `[TEST]`/`TEST-`
record belongs to the `FTEST` tenant), all six Item Types exist, both Tire
vehicle groups exist, Scheduler coverage around the reference date, both
pre-Delivered and Delivered External WO states exist, and the Settled
scenario is an exact-match payment.
