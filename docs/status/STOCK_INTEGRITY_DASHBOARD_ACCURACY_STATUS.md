# Stock integrity and dashboard accuracy — Status

Branch `claude/stock-integrity-dashboard-accuracy`, baseline `origin/main` = `96441e1`
(PR #29 merged). Continuation checkpoint between sessions; the repository is the source of truth.

## Checkpoints

| # | Checkpoint | Status | Commit |
|---|---|---|---|
| 1 | Audit and bug reproduction | DONE | this commit |
| 2 | Stock movement fix + tests | TODO | |
| 3 | Reconciliation audit + dry-run / apply | TODO | |
| 4 | Data completeness + widget formulas | TODO | |
| 5 | UI + bilingual | TODO | |
| 6 | Seeders, regression, final QA | TODO | |

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

## Open decisions / blockers

None yet. Data corrections on existing operational data will not be applied without the owner's
confirmation of the dry-run plan (CP3).
