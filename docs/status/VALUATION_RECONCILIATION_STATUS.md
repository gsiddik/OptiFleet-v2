# Valuation status and reconciliation handling — Status

Branch `claude/valuation-reconciliation-improvements`, baseline `origin/main` = `4a63fe3` (PR #30 merged).
Continuation checkpoint between sessions; the repository is the source of truth.

## Checkpoints

| # | Checkpoint | Status | Commit |
|---|---|---|---|
| 1 | Revalidation + minimal design | DONE | this commit |
| 2 | Valuation status + data / API compatibility | DONE (backend; widget use in CP4) | see git log |
| 3 | Reconciliation, opname evidence, approved adjustments | DONE (API + UI) | see git log |
| 4 | Widgets, scope labels, bilingual | TODO | |
| 5 | Seeders, regression, visual QA | TODO | |

## CP1 — what exists (verified in code) and the gaps

- **Ledger / valuation.** `warehouse_stocks` holds one moving-average balance per (warehouse, product):
  `quantity_on_hand` and `average_unit_cost` (NOT NULL default 0). `stock_movements` is immutable; inbound
  value-bearing writers are `InventoryService::receive` (Goods Receipt at the PO unit price, transfer receipt at the
  source's average cost at dispatch, opening) — `returnStock`, `adjust` and opname variances move quantity at the
  existing average cost. A cost of 0 is therefore indistinguishable from "free", "not valued" and "never recorded".
  There is no costing method other than the moving average, and no finance journal tied to stock movements.
- **Stock opname** (`StockOpnameService`): DRAFT → COUNTING → SUBMITTED → APPROVED → POSTED. Every item stores the
  system quantity at CREATION (`system_quantity`), the physical count and the variance; `post` applies
  `physical − snapshot` to the CURRENT on-hand (so a movement between creation and posting makes the posted variance
  stale) and writes a `STOCK_OPNAME` movement with `reference_type = 'StockOpname'`, `reference_id = NULL`, the
  opname number only in the free-text reason — the movement cannot be joined to its opname. Gap, not rebuilt here.
- **Approval mechanisms.** The generic `WorkflowApprovalService` (permission-based steps, per-resource
  `WorkflowDefaultsSeeder` definitions, maker-checker enforced by the caller as in `UsedPartDispositionService`).
  Stock reconciliation will reuse it as a new resource type; no parallel approval engine.
- **Reconciliation (PR #30).** `SerializedStockReconciliationService`: read-only plan + a hash-only `apply` that books
  the ISSUE directly (no maker-checker, no stored reason / approver, no opname evidence). This is the part to
  complete: apply is replaced by a proposal → approval → apply flow.
- **Widgets.** FN-05 (PR #30) separates valued from pending stock but infers "pending" from the number
  (`average_unit_cost <= 0`) — exactly what must not be inferred.

## Minimal design

### 1. Valuation status — grain and rules
Grain follows the existing model: provenance on the **movement**, status on the **balance** (there are no lots).

- `stock_movements` (inbound value-bearing only; NULL elsewhere): `valuation_status`, `valuation_basis`,
  `purchase_unit_price` — purchase price, valuation basis and valuation status are three separate facts.
- `warehouse_stocks`: `valuation_status` (NULL when no stock), `valuation_basis`. Values:
  `VERIFIED` (positive cost with a traceable source), `VERIFIED_ZERO` (zero value with a documented basis),
  `NOT_VALUED` (evidence that valuation was not performed), `UNVERIFIED` (status not verified / "belum
  terverifikasi"), `MIXED` (the balance combines sources with different statuses — it is never presented as verified).
- Inbound rule (in `InventoryService::receive`, under the balance lock): Goods Receipt with PO price > 0 →
  `VERIFIED` / `PO_UNIT_PRICE`; Goods Receipt at price 0 (free goods) → `UNVERIFIED`, `purchase_unit_price = 0`, no
  basis; opening balance → `UNVERIFIED`; transfer receipt inherits the source balance status captured at dispatch
  (`stock_transfer_items.valuation_status`). The incoming status combines with the existing balance
  (empty → incoming; same → same; both verified kinds → verified; otherwise `MIXED`). Costs are never changed.
- Review (`stock_valuation_reviews`, append-only): actor, time, from/to status, basis, reason, evidence reference,
  quantity and unit cost at review. Backend permission `inventory_valuation.verify`; a review never edits the cost
  (revaluation = costing-method change, out of scope); verifying a `MIXED` balance needs an explicit
  acknowledgement; `VERIFIED` needs a positive cost, `VERIFIED_ZERO` a documented basis.
- Backfill (separate migration, new columns only, deterministic, never invents evidence): a movement is `VERIFIED`
  only when its Goods Receipt line exists with the same product and unit cost > 0; everything else is `UNVERIFIED`.
  A balance with stock and cost 0 is `UNVERIFIED` whatever the movements say; otherwise all-verified → `VERIFIED`,
  all-unverified → `UNVERIFIED`, both → `MIXED`. Used tire stock has no valuation concept → `NOT_VALUED` by definition.

### 2. Reconciliation of old installations
Original history is never edited. Categories: `PROVABLE_UNDEDUCTED`, `COVERED_BY_WO_ISSUE`, `AMBIGUOUS_*`,
`CORRECTED_BY_RECONCILIATION` (already corrected through an approved adjustment), `RESOLVED_BY_OPNAME` (a posted
opname of that warehouse / product is later than the installation: the physical count already settled the quantity —
no adjustment is allowed). Adjustments (`stock_reconciliation_adjustments`): PENDING_APPROVAL → APPROVED → APPLIED
/ REJECTED (+ SUPERSEDED when opname evidence overtakes it). Proposal recomputes the case and snapshots the evidence;
approval via `WorkflowApprovalService` with maker ≠ checker; apply (separate permission) re-validates (no exit row yet,
no later posted opname, stock sufficient) and books an ISSUE through `InventoryService` now (never back-dated),
referencing the adjustment + the old installation, then writes the exit record `RECONCILED` — all in one transaction
with row locks; unique constraints prevent a second adjustment / second application.

### 3. Tires without a Goods Receipt link
Never adjusted automatically and never linked to a receipt by guess. The report lists them as ambiguous per
(warehouse, product) with the system quantity, the latest posted opname (number, time, system snapshot, physical
count, variance) and flags `snapshot_stale` (movements between the opname's creation and posting) and
`movements_after`. Opname evidence proves the quantity at that time, not the historical cause — the report says so.

### 4. Permissions / scope
`inventory_valuation.view`, `inventory_valuation.verify`, `inventory_reconcile.view`, `inventory_reconcile.manage`
(propose, apply), `inventory_reconcile.approve`. Module INVENTORY, warehouse data scope on every query / action.
Granted to existing tenants like `mechanic_baseline.manage` (migration, roles holding `role.assign_permission`).

## Out of scope (kept)
Used-stock valuation method, non-serial installation tracking, PR approval timestamp, any new costing method or
journal, rebuilding stock opname. Opname gaps found are reported, not fixed.

## CP2 — delivered
- Migrations `2026_10_19_000001..3` (schema / backfill of new columns only / permissions), `ValuationStatus`,
  `InventoryService::receive` provenance, `StockValuationService` + `InventoryValuationController`
  (`GET /app/inventory/valuation`, `GET .../{id}`, `POST .../{id}/review`), opname movements now carry `reference_id`.
- Tests: `tests/Feature/Valuation/ValuationStatusTest.php` (9) + regression of stock-integrity, dashboard, inventory,
  transfer, goods receipt, WO stock, used stock suites (121 passed).

## CP3 — delivered
- `stock_reconciliation_adjustments` (partial unique index: one live adjustment per installation), workflow resource type
  `stock_reconciliation` (run `BootstrapSeeder` / `WorkflowDefaultsSeeder` on deploy), `StockReconciliationAdjustmentService`
  (propose → approve/reject with maker ≠ checker → apply, re-validated under row locks, ISSUE booked now referencing the
  adjustment), report with opname evidence (`RESOLVED_BY_OPNAME`, `snapshot_stale`, `movements_after`), tires report-only.
- The hash-based `--apply` of PR #30 is removed; the command now only dry-runs or files proposals (`--propose --user --reason`).
- UI: Inventory → Stock Valuation, Inventory → Stock Reconciliation (Report / Adjustments), EN + ID via the CSV pipeline.
- Opname gaps found, NOT rebuilt: posted variance applies to current on-hand (flagged `snapshot_stale`); opname movements now
  carry `reference_id` going forward (older ones stay unlinked).
