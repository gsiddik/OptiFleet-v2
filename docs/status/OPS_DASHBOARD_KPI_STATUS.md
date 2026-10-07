# Operational Dashboard KPIs — Status

Continuation checkpoint for the second Tenant Dashboard improvement (six required widgets, four
approved extra KPIs, work-interval foundation, layout). Builds on `DASHBOARD_IMPROVEMENT_STATUS.md`.

- **Baseline:** `origin/main` @ `f33984b` (PR #28 merged; identical tree to the previous branch head).
- **Branch:** `claude/ops-dashboard-kpi` (new branch from `main`, owner decision — no force-push).

## Audit of the current code (verified on `f33984b`)

| Area | Fact | Consequence |
|---|---|---|
| WO transitions | Single entry point `WorkOrderTransitionService::transition` (row lock + workflow-engine check). Default graph: SCHEDULED→IN_PROGRESS, IN_PROGRESS→QC_PENDING/ON_HOLD/WAITING_PART/CANCELLED, ON_HOLD/WAITING_PART→IN_PROGRESS, QC_PENDING→COMPLETED/REWORK, REWORK→IN_PROGRESS | Work intervals are recorded there, in the same transaction |
| WO status history | No status-history table. `work_orders.started_at` is only the first start. Generic `audit_logs` (Eloquent `updated` events) hold status diffs but are not guaranteed complete (query-builder updates bypass them) | New auditable interval table; WOs started before it are "history incomplete" — no backfill |
| Mechanic assignment | `work_order_mechanic_assignments`: worker, PRIMARY/ASSISTANT, per job or WO, `assigned_at`/`unassigned_at`, `hourly_rate_snapshot` taken at assignment (`workers.hourly_rate`) | Attribution = overlap of interval × assignment; rate = assignment snapshot |
| Labor logs | `work_order_labor_logs` per job/worker timer (start/pause/finish); rarely used | Not used for attribution (owner decision) |
| Consumption | `work_order_planned_parts`: `consumed_quantity`, issue snapshot `unit_cost_at_issue`/`total_cost`; each consume writes an immutable `CONSUME` ledger row (`reference_id` = planned part, `occurred_at`). Consumption is never reversed (returns only cover unconsumed issued quantity). USED-condition lines (used tires) have no ledger row and no valuation (cost 0 by app rule) | Cost per consumption = qty × round(total_cost / issued, 4) — same unit basis as Service Cost FN-01 |
| Tires | Serialized `tires` + `tire_installations` (vehicle, position, WO, installed/removed). WO replacement tires are consumed as planned parts (tire product) and installed on consume. `tires.purchase_cost` unused (all null) | Installed tire cost = consumption snapshot of the installing WO; directly registered tires: cost not available |
| Rims / serialized parts | `component_assets` + `component_installations` (vehicle, WO, installed/removed); `purchase_cost` from Goods Receipt; registered rims have no cost | Installed cost = purchase cost; else "cost not available" |
| Non-serialized parts | No install/removal tracking | Not shown in Installed (documented gap) |
| External cost | External WO invoice: `settle` requires the exact invoice amount (`payment_date`, `paid_amount`) — no partial payment. Service Invoice: one full payment (`workshop_invoice_payments`) | Paid cost recognized on payment date; partial payment impossible in data |
| Stock | `warehouse_stocks`: `quantity_on_hand`, `average_unit_cost`, `reorder_point`/`minimum_stock` NOT NULL default 0 (empty ≠ zero not storable; the edit API already sends null). Tires/rims in stock are part of the ledger by product. `used_tire_stocks` has quantity only (no valuation). Reservations retired | Threshold = `reorder_point` vs on hand; column made nullable; used stock shown as quantity, never valued |
| Existing widgets | WH-02 Critical Stock = low stock list; FN-05 Inventory Value = value by warehouse | Low Stock enhances WH-02; On-hand by Item Type extends FN-05 (no duplicates) |
| Threshold edit | `PUT /app/inventory/{stock}/thresholds` (permission `inventory.adjust`) | Reused for the "set threshold" action |

## Owner decisions (this session)

1. Pauses: interval closes on ON_HOLD / WAITING_PART (and any exit from IN_PROGRESS) and reopens on resume.
2. Multi-mechanic: each mechanic gets the full overlap of interval × own assignment window (man-hours); cost = hours × assignment `hourly_rate_snapshot`.
3. Running WO: an open interval counts up to "now".
4. Period: an interval's hours/cost belong to the month it ends (open interval → current month).
5. External paid cost: External WO settlements + Service Invoice payments, on payment date; cancelled invoices excluded.
6. Used Times = number of distinct WOs consuming the product in the period.
7. Installed: tires = installing WO consumption snapshot; rims / serialized parts = GR purchase cost; no basis → "cost not available"; non-serialized not shown (gap documented).
8. Threshold: `reorder_point` nullable; existing 0 values migrated to "not set"; compared with on hand.
9. Mechanic Performance: a WO counts for a mechanic when their assignment overlaps a work interval; average = mechanic's attributed hours per WO over COMPLETED/CLOSED WOs with complete history.
10. Baseline: per tenant × maintenance type (hours).
11. New permission `mechanic_baseline.manage` (Tenant Admin).
12. KPI status: ≥ 5 completed WOs with complete history; average ≤ baseline = meets.
13. Extra KPIs approved: Rework / QC first-pass rate, Part fulfilment lead time, Procurement cycle PR→PO→GR, Monthly cost mix.

## KPI matrix per role (business perspective)

| User | Business question | Existing widget | Gap | Widget (this batch) | Source | Priority |
|---|---|---|---|---|---|---|
| Operations Manager | Which vehicles consume the most money, and why? | FN-02 (Service Cost by vehicle, document basis) | Labor not included; paid basis | **FN-07 Most Costly Vehicle** | consumption, intervals × rates, payments | Required |
| Operations Director | Is maintenance spend shifting to labor/external? | FN-01 (document basis) | No labor; no mix trend | **FN-08 Cost mix per month** | same model as FN-07 | Approved extra |
| Finance Director | What stock value sits in each item type? | FN-05 (by warehouse) | No item-type view | **FN-05 extended: by Item Type** | `warehouse_stocks` | Required |
| Fleet Manager | What is installed on each vehicle and at what cost? | — | No installed view | **FL-07 Installed Components** | tire / component installations | Required |
| Warehouse Manager | What runs out, and what is consumed most? | WH-02 (critical stock) | Threshold not set ≠ 0; no settings link | **WH-02 improved Low Stock**, **WH-06 Most Used Parts** | `warehouse_stocks`, consumption | Required |
| Warehouse / Workshop Manager | How long do WOs wait for parts? | WS-06 (waiting now) | No lead-time trend | **WH-07 Part fulfilment lead time** | part requests, intervals | Approved extra |
| Procurement Officer | How long from need to receipt? | PR-02, PR-04 | No cycle time | **PR-05 Procurement cycle PR→PO→GR** | PR / PO / GR | Approved extra |
| Workshop Manager | Which mechanics are slower than baseline? Rework? | WS-04 (turnaround) | No per-mechanic view; no rework rate | **WS-07 Mechanic Performance**, **WS-08 Rework rate** | intervals, assignments, baseline | Required / Approved extra |
| Branch Manager | All of the above for my branch | presets by scope | — | same widgets, scope-filtered | — | — |

Not proposed (data not verifiable today): mechanic utilization (no shift/capacity data), stock turnover
(no historical inventory snapshots), budget vs actual (no budget data).

## Metric definitions (agreed)

- **Work interval:** opens on any transition to IN_PROGRESS, closes on any transition out of
  IN_PROGRESS; cycle = 1 + number of REWORK entries before it. Time waiting for/at QC, on hold or
  waiting for parts is excluded. History complete ⇔ the WO's first interval starts at `started_at`.
- **Mechanic hours** = Σ overlap(interval, assignment window) per mechanic (same mechanic's
  overlapping assignments on several jobs counted once); **mechanic cost** = hours × snapshot rate.
- **FN-07 Most Costly Vehicle** = parts/tires consumed (consumption date) + mechanic cost (interval
  end month) + external paid (payment date). Not the same basis as Service Cost FN-01/02/03.

## CP2 — foundation (implemented)

- `work_order_work_intervals` (migration `2026_10_17_000001`): written in `WorkOrderTransitionService`
  inside the row-locked transition (open on → IN_PROGRESS, close on IN_PROGRESS → any; cycle +1
  from REWORK); partial unique index = one open interval per WO; CHECK ended ≥ started.
- `WorkTimeQuery`: intervals by end-month, per-(interval, mechanic) overlap seconds and cost
  (BigDecimal, rate snapshot of the most recent active assignment; missing rate ⇒ cost null),
  history-complete flag. Labor logs untouched.
- `mechanic_performance_baselines` + `GET/PUT /app/dashboard/mechanic-baselines`
  (module WORK_ORDER + `mechanic_baseline.manage`); null = not set.
- `warehouse_stocks.reorder_point` nullable, existing 0 → null; WH-01 gains NOT_SET state, WH-02 no
  longer fails on rows without a threshold; threshold endpoint stores 0 for a cleared minimum stock
  (pre-existing 500 error fixed).
- Widget-specific filters (`paramRules()`, part of the cache key).
- Tests: `WorkIntervalTest` (4), `DashboardConfigurationTest` (3); impacted suites 320 passed.

## CP3 — cost and mechanic widgets (implemented)

- `OperatingCostQuery` (PARTS ledger × issue snapshot / LABOR from WorkTimeQuery / EXTERNAL_PAID
  payments) behind **FN-07 Most Costly Vehicle** (all vehicles in scope incl. 0, top 10 stacked bar,
  in-card category / vehicle filters, drill ranking → vehicle WOs → WO lines) and **FN-08 Cost mix**
  (monthly, same totals; month → lines). Permission `dashboard.finance.view`, module WORK_ORDER.
- **WS-07 Mechanic Performance** (per maintenance type, scatter X = WOs completed, Y = average hours,
  baseline line, status table, baseline dialog for `mechanic_baseline.manage`, "ask an authorized
  user" otherwise). Permission `work_order.view` + `worker.view`.
- **WS-08 Rework / first-pass rate** (complete histories only, per month and workshop).
- Tests: `DashboardOperatingCostTest` (2), `DashboardMechanicPerformanceTest` (2).

## Checkpoints

| # | Checkpoint | Status | Commit |
|---|---|---|---|
| 1 | Audit, metric definitions, decisions | DONE | this commit |
| 2 | Work intervals, assignment attribution, snapshots, configuration | DONE | CP2 commit |
| 3 | Most Costly Vehicle + Mechanic Performance (+ FN-08, WS-08) | DONE | CP3 commit |
| 4 | Consumption, installed, inventory value, low stock (+ extras) | TODO | |
| 5 | Layout + bilingual | TODO | |
| 6 | Seeders, regression, final QA | TODO | |
