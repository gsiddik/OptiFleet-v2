# Stock integrity and dashboard accuracy — Status

Branch `claude/stock-integrity-dashboard-accuracy`, baseline `origin/main` = `96441e1`
(PR #29 merged). Continuation checkpoint between sessions; the repository is the source of truth.

## Checkpoints

| # | Checkpoint | Status | Commit |
|---|---|---|---|
| 1 | Audit and bug reproduction | DONE | 9b46022 |
| 2 | Stock movement fix + tests | DONE | bb7dd6b |
| 3 | Reconciliation audit + dry-run / apply | DONE | c4fc3be |
| 4 | Data completeness + widget formulas | DONE | eb8f6d1 |
| 5 | UI + bilingual | DONE | d9c6464 |
| 6 | Seeders, regression, final QA | DONE | CP6 commit |

## CP1 — audit of the installation paths against the inventory ledger

### How the ledger works (verified in code)

- `warehouse_stocks.quantity_on_hand` + immutable `stock_movements` are the ledger, per
  (warehouse, product). `InventoryService::issue` is the only call that decrements on-hand for an
  outgoing unit; `recordConsumption` (CONSUME), `recordSale` and `recordRemovedComponentReturn` are
  zero-balance-effect markers by design (owner decision: removed components never silently become
  available stock).
- Goods Receipt posts `+qty` to the ledger **and** generates one `component_assets` row per accepted
  unit (`IN_STOCK`, `current_warehouse_id`, `goods_receipt_item_id`). Serial tires are registered
  separately (`TireRegistrationService`, `IN_STOCK`, optional warehouse) and are not GR-linked.
- A Work Order part line is issued through a Part Request → `WorkOrderPartService::issue` →
  `InventoryService::issue` (ledger −qty, snapshot cost). `consume` writes the CONSUME marker only.
- Serial rows (`component_assets`, `tires`) and installations carry **no link** to the ledger or to the
  issued part line.

### Root cause

`ComponentAssetService::install` (and `TireService::install` for new stock tires) only changes the
serial's status / creates the installation row. Nothing calls `InventoryService::issue`, so a unit
installed straight from the warehouse stays in `warehouse_stocks.quantity_on_hand` (and in Inventory
Value) while also being counted as installed (Installed Components). Reproduced: GR of 2 units →
on-hand 2; install one → on-hand still 2 (expected 1).
Secondary defects found: the status guard runs on a stale model **outside** the transaction (two
concurrent installs of the same asset race to the DB unique index only), and nothing records *how* a
unit left the warehouse, so a Work-Order-issued unit cannot be told apart from a direct one.

### Matrix

| Path | Status before | Operation | Where stock is decremented today | Movement today | Status after | Double-deduction risk if install also decrements |
|---|---|---|---|---|---|---|
| Component: direct from warehouse (`POST /component-assets/{id}/install`) | IN_STOCK | install | **nowhere** (bug) | none | INSTALLED | n/a — must decrement once, at install |
| Component: Part Request issue → install (WO) | IN_STOCK | issue, (consume), install | at issue (`InventoryService::issue`) | ISSUE (+ CONSUME marker) | INSTALLED | **yes** — install must not decrement again |
| Component: Part Request issue → consume → install | IN_STOCK | issue, consume, install | at issue | ISSUE + CONSUME | INSTALLED | yes (same) |
| Component: retry / double submit of install | INSTALLED | install | n/a | none | rejected (422) | none; but the check is outside the transaction (race) |
| Component: replace (old out, new in) | IN_STOCK (new) | replace → install | nowhere (bug, same as direct) | none | new INSTALLED | same as direct / WO |
| Component: remove | INSTALLED | remove (REUSE / REPAIR / SCRAP) | not a stock operation | none | REMOVED / UNDER_REPAIR / SCRAPPED | removal must not credit stock |
| Component: removed → WO "Removed Components" return to warehouse | — | `recordRemovedComponentReturn` | zero-effect marker | REMOVED_COMPONENT_RETURN | unchanged | none |
| Component: repair completed (default outcome) | UNDER_REPAIR | `completeRepair` | none | none | **IN_STOCK again, not ledgered** | install of such a unit must not decrement (it never re-entered the ledger) — ambiguity flagged |
| Component: initial registration (Rim register, installed) | none | `RimRegistrationService` | none (not a warehouse issue, by contract) | none | INSTALLED | none — correct, no warehouse involved |
| Tire (new, IN_STOCK / RESERVED, in a warehouse): `POST /tires/{id}/install` | IN_STOCK | install | **nowhere** (bug) | none | INSTALLED | n/a |
| Tire: Tire Operation replacement via WO Part Request (NEW line) | IN_STOCK | issue → consume → `replace` → install | at issue | ISSUE + CONSUME | INSTALLED | yes — install must not decrement again |
| Tire: REUSE (used stock) install / USED WO line | REUSE | issue from used stock, install | `UsedTireStockService` (separate used stock) | `used_tire_stock_movements` | INSTALLED | none — separate ledger, untouched |
| Tire: initial registration on a vehicle (`VehicleTireRegistrationService`) | none | install | none (by contract: "never touches stock") | none | INSTALLED | none |
| Tire: rotate / swap | INSTALLED | close + reopen installation | not a stock operation | none | INSTALLED | none |
| Tire / component: removal | INSTALLED | remove | not a stock operation | none | REMOVED (inspection / disposition) | removal must not credit stock |
| Stock transfer / opname / scrap / sale / return-to-vendor | — | product-level ledger ops | unchanged | unchanged | — | out of scope, not touched |

### Design (CP2)

One domain service (`SerializedStockExitService`) called from `ComponentAssetService::install` and
`TireService::install` inside the installation transaction — no controller logic:

1. Lock the serial row first, re-check status under the lock (closes the race).
2. A unit is **ledger-backed** only when it is IN_STOCK (tires: IN_STOCK / RESERVED), sits in a
   warehouse, and has never been installed before (a unit that came back through repair is not
   ledgered — owner decision "removed never becomes available stock").
3. Ledger-backed + the installing WO has an issued, non-USED part line for the same product and
   warehouse with uncovered capacity (issued quantity − installations already covering it, line row
   locked) → **covered by the issue**: record the source, no movement.
4. Ledger-backed otherwise → **direct issue**: `InventoryService::issue(warehouse, product, 1, …)`
   (insufficient stock → rejected, whole transaction rolls back).
5. Not ledger-backed → no movement, recorded as `NOT_LEDGERED` (visible in reconciliation).
6. The decision is stored in `installation_stock_exits` (unique per installation) with the movement /
   part line reference — the audit basis for reconciliation and for idempotency.

Pre-existing data keeps no exit record (history is not rewritten); reconciliation reports it.

## CP2 — fix (implemented)

- Migration `installation_stock_exits` (one row per installation, unique): source `DIRECT_ISSUE` (movement) /
  `WO_ISSUE` (planned part) / `NOT_LEDGERED` (reason `NO_WAREHOUSE`, `PREVIOUSLY_INSTALLED`, `NOT_NEW_STOCK`,
  `USED_STOCK`), CHECK constraints tie each source to its reference.
- `SerializedStockExitService` called from `ComponentAssetService::install` and `TireService::install`; both now
  lock the serial row first and re-check on the locked row (the old guard ran on a stale model outside the
  transaction). The installation row is created under a savepoint; the vehicle's tenant and the Work Order's
  tenant / vehicle are validated.
- Tests (`tests/Feature/StockIntegrity/SerializedInstallationStockTest`, 10): direct −1 once; WO issue → install no
  second deduction; issue → consume → install; capacity of an issued line covers one serial only; retry and stale
  model do not move stock; insufficient stock and mismatching Work Order / tenant roll back with no side effect;
  removal adds no stock and a re-install is NOT_LEDGERED; repaired unit back in stock is NOT_LEDGERED; new-stock
  tire follows the same rule; initial tire registration (no warehouse) never touches stock.
- Existing fixtures that installed units with no stock in the ledger were corrected (`ComponentAssetTest`,
  `SupplyChainSeeder`, `DashboardDemoSeeder`): units are received into the ledger through `InventoryService`.
- Before / after (same GR of 2 units, install 1): on-hand 2 → **2** before the fix, 2 → **1** after; WO issue then
  install: 2 → 2 after issue, still **2** after the installation (one ISSUE movement in total).

## CP3 — reconciliation (implemented; data correction NOT applied)

- `inventory:reconcile-serialized-installations` (service `SerializedStockReconciliationService`): default
  dry-run, `--json=` writes every installation with its evidence; `--apply --plan-hash=` books the missing issue
  only for the plan the owner reviewed (hash of the actionable installation ids), as a normal ISSUE movement booked
  now (never back-dated) whose reason names the original installation date + an exit row `RECONCILED`; unique exit
  rows make a second apply a no-op; an uncoverable unit is skipped (`INSUFFICIENT_STOCK`), never forced.
- Categories: `PROVABLE_UNDEDUCTED` (GR-linked component, no covering WO issue — the only actionable one),
  `COVERED_BY_WO_ISSUE`, `AMBIGUOUS_NO_RECEIPT`, `AMBIGUOUS_TIRE`, `NOT_WAREHOUSE_ORIGIN`.
- Dry-run on the demo database as it was before the fix (disposable, pre-fix data): 6 `PROVABLE_UNDEDUCTED`
  (batteries AST-2026-000013..18, installed on H 3201 ALP without an issue), 5 covered by WO issue (tires),
  46 ambiguous components (no receipt link: rims registered as installed / legacy), 9 ambiguous tires, 42 tires
  registered directly on vehicles. Ledger check corroborates: Semarang warehouse ledger 7 vs 1 unit registered
  in stock = difference 6 = the 6 provable cases. **Nothing was applied.**

## CP4 — widget accuracy (implemented)

- Contract: every affected widget returns `basis` = date basis per part, counted / not counted records, completeness
  (`COMPLETE` / `PARTIAL` / `UNAVAILABLE` with valid / excluded counts and reasons) and the date work-time history
  starts. A missing value is `null` + reason, never 0; nothing is estimated.
- FN-07 / FN-08: payment contract verified (one full payment per Service Invoice — unique index; external WO
  settlement = full invoice amount; no reversal; cancellation only for unpaid invoices). Cost = payments, dated by
  payment date; invoices alone are never counted. Records breaking the contract are reported, not altered
  (`EXTERNAL_PARTIAL`, `EXTERNAL_INCOMPLETE`, `SERVICE_PARTIAL`, `SERVICE_CANCELLED_PAID`, `NON_BASE_CURRENCY`;
  drill-down `view=payment_anomalies`). Mechanic cost per vehicle / WO is `null` + `labor_status` UNAVAILABLE when
  started Work Orders have no recorded work time; partial history is `PARTIAL`; vehicles with no started WO are a
  true zero (`NONE`). Totals say "recorded costs only" when incomplete.
- WS-07 / WS-08: population = completed WOs assigned to the mechanic; excluded (`WORK_TIME_UNAVAILABLE`,
  `WORK_TIME_PARTIAL`, `NO_ATTRIBUTED_TIME`) counted and listed in the drill-down; no valid sample → average
  `null` (`avg_state` UNAVAILABLE).
- FL-07: non-serial consumed parts reported as `untracked` (lines / products / quantity per UOM, no value);
  installed value is `null` when no item has a cost basis. PR-05: labelled "since PR creation" (no approval
  timestamp exists; nothing is back-filled). FN-05: total = stock with a recorded valuation; used stock and
  zero-cost stock are `pending_valuation` (SKUs, quantities per UOM, drill-down), never valued.
- Tests: `DashboardDataCompletenessTest` (6) incl. EN/ID text for every code emitted.

## CP5 — UI and bilingual (implemented)

- `BasisNote` under each affected widget (date basis line, completeness badge when partial / unavailable,
  "how this is calculated" disclosure), "Unavailable" cell / KPI / tooltip instead of 0 or a bare dash,
  payment-issue and pending-valuation drill-downs, `*` marker on vehicles with recorded costs only.
  122 new strings through `docs/i18n/17-i18n-additions.csv`; "Item Type" → "Jenis Item" in Indonesian.

## CP6 — seeders, regression, QA (see report)

- Demo seeder: serial batteries through PO → GR; direct installation, repeated installation (rejected), Work
  Order issue → consume → install, removed component received back through the Removed Components flow
  (zero-balance marker), opening stock without a unit cost, two Work Orders that predate work-time tracking
  (intervals removed after the fact: one unavailable, one partial). Fresh seed and rerun give identical counts;
  the reconciliation of the fresh seed finds 0 `PROVABLE_UNDEDUCTED`.

- Validation: full non-Mongo backend regression 1262 passed / 0 failed (before the last small refinement:
  "no mechanic assigned" = unavailable mechanic cost); after it Dashboard + StockIntegrity + seeder suites 63 passed.
  Frontend tsc, oxlint (0 errors), 52 unit tests, i18n check / audit, build pass. MongoDB suites NOT RUN (no MongoDB).
  Visual QA 1440 / 820 / 390 px, EN + ID, admin / mechanic / no-permission users, no horizontal overflow.

## Open decisions / blockers

- Applying the reconciliation on real operational data needs the owner's confirmation of a dry-run plan (not run
  against production here).
- Tires are not linked to a Goods Receipt: their pre-fix installations can only be `AMBIGUOUS_TIRE` (or covered by a
  WO issue) — correcting them needs a decision (e.g. confirm per warehouse from a physical count / opname).
- A ledger row with quantity and unit cost 0 is treated as "no recorded valuation" (cannot be told apart from a true
  zero cost): confirm if free stock must be valued at 0.
