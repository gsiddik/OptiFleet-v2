# Maintenance History Scope & Purchase Order Remaining Quantity — Status

Branch `claude/magical-volta-tv4xwl`, baseline `main` @ 558a344 (merged as 01dced5, no content
difference).

| Phase | Scope | Status | Commit |
|---|---|---|---|
| 1–3 | Global Maintenance History by data scope; Vehicle History stays vehicle-specific; scope tests | DONE | e4cea0a |
| 4–8 | Remaining Receivable Qty rule, single source of truth, Post GR guard, DB check | DONE | a39f559 |
| 9 | Seeders (history for every vehicle; Engine Oil Filter lifecycle; issued PO) | DONE | e4cea0a, 2961735 |
| 10–13 | Contract, QA, quality gates | DONE | see git log |

## Maintenance History

- `GET /maintenance-history` (`maintenance_history.view`): every vehicle in the user's data scope —
  TENANT scope = all branches of the tenant, BRANCH scope = the assigned branches
  (`DataScopeService::allowedBranchIds`, no role names). Optional filters: branch, vehicle, event
  type, date range, registration search; a branch / vehicle outside the scope → 403, another
  tenant's vehicle → 404. Server-side pagination (25, max 100), newest first.
- `GET /vehicles/{id}/history` (Vehicle History) unchanged contract, same scope check (403).
- One `HistoryService::query()` serves both: a UNION of inspections, maintenance requests,
  breakdowns, Work Orders, QC inspections and vehicle releases, joined once to vehicles and
  branches (vehicle identity on every row — no N+1; existing `(tenant_id, vehicle_id)` indexes).
- Frontend: History → Maintenance History is the new global page (`/app/maintenance-history`,
  no vehicle dropdown; registration, vehicle, branch, event, details columns; filters; pagination).
  Vehicle → History keeps the vehicle dropdown (now titled Vehicle History).

## Purchase Order Remaining Quantity

**Owner rule (DECISION REQUIRED, answered):** every Goods Receipt and every Return to Vendor reduce
Remaining once; a Redelivery Request or a rejected refund re-opens the returned quantity exactly
once (the replacement is received — and counted — by Goods Receipt); an accepted refund changes
nothing more. No separate "refund undelivered quantity" flow.

    Remaining = Ordered − Received − Returned + Reopened = Ordered − Received − Refunded

(`quantity_refunded` holds requested + accepted refunds and is released when the vendor rejects.)

**Mandatory case** — 24 ordered; 11 received; return 2 → redelivery → GR 2; return 5 → redelivery →
GR 5; return-for-refund 5 accepted: Received 18 (cumulative Goods Receipt), Returned 12, Reopened 7,
Refunded 5 → **Remaining 24 − 18 − 12 + 7 = 1** (was 13). Post GR: max 1; 2 → 422, no receipt, no
stock movement. If that refund is rejected instead: Remaining 6; after the replacement GR of 5: 1.

**Root cause of 13:** the old formula `Ordered − (Received − Returned) − Refunded` added every
returned unit back as receivable. For the 7 units returned for redelivery the replacement Goods
Receipts (counted in Received) and the returns (still added back) both stayed in the formula, so
those 7 were counted twice: 24 − (18 − 12) − 5 = 13.

Note: with "Post GR records redelivery", the owner's "Received 18" is the cumulative Goods Receipt
quantity (11 + 2 + 5). Starting from 18 original units, the second replacement receipt (5) would
exceed Remaining (4) under the owner rule.

- `PurchaseOrderQuantityService` — single source of truth; per line: ordered, gross received,
  returned, reopened for redelivery, refund requested, accepted refund, net held, remaining
  receivable. Exposed in the PO `return_summary.items`; `remaining_quantity` kept as alias.
- Guards: Goods Receipt needs a quantity > 0 and ≤ Remaining, under the PO row lock (concurrent
  receipts serialize); a return-for-refund cannot exceed Remaining (it is not re-opened, so it
  would push Remaining below zero — return the rest as a Redelivery Request); CHECK
  `received + refunded ≤ ordered` (`2026_10_09_000001`, added NOT VALID only if legacy rows violate
  it — none in seeded data, so it is validated).
- Frontend: PO detail shows the backend breakdown and Remaining, flags quantities above it and
  disables Post Goods Receipt; no independent formula remains.
- Existing `PurchaseReturnTest` expectations that encoded the old rule were updated to the owner
  rule (refund request: Remaining 10 − 6 − 2 = 2; rejected refund / redelivery: 4).

## Seeders

- Every ALPHA vehicle has maintenance history (a breakdown is reported through the service for any
  vehicle without one); the Bandung branch admin sees only Bandung's 3 vehicles.
- PO cases: ordered not received (new), partial, partial + refund requested, return + redelivery,
  refund accepted, refund rejected → redelivery, fully received (remaining 0), and the Engine Oil
  Filter lifecycle (remaining 1) — all through the procurement services; idempotent.

## Quality gates

| Gate | Result |
|---|---|
| Backend targeted (MaintenanceHistoryScopeTest 5, PurchaseOrderQuantityTest 7, PurchaseReturnTest, ProcurementTest, GoodsReceiptVendorInvoiceTest, HistoryAndDowntimeTest, VehicleTest, seeder tests) | PASS |
| Backend full regression (non-Mongo, PostgreSQL) | PASS — 1122 tests, 7197 assertions |
| Mongo-dependent tests (Analytics / Intelligence) | NOT RUN — MongoDB is not available in this environment (no analytics code changed in this task) |
| `npm run lint` | PASS — 0 errors, 27 warnings (unchanged baseline) |
| `npm run build` (includes `tsc -b`) | PASS |
| `npm run typecheck` / `npm test` | not defined in package.json |
| `migrate:fresh --seed` + `db:seed` re-run | PASS — Engine Oil Filter Remaining 1; counts unchanged on re-run |
| e2e (Playwright, freshly seeded DB) | PASS — Maintenance History scope 14/14, PO quantity 9/9, PO Return regression 21/21 |

## Remaining risks

- Behaviour change for existing data: lines with returns now follow the owner rule (a redelivery
  return no longer adds its quantity on top of the original outstanding quantity), so Remaining on
  such existing lines can drop; the CHECK constraint is added NOT VALID if any legacy row violates it.
- Concurrency of Post Goods Receipt is guarded by the PO row lock (SELECT … FOR UPDATE); not load-
  tested with parallel requests in this environment.
