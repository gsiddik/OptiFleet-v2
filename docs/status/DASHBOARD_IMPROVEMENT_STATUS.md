# Tenant Dashboard Improvement — Status

Continuation checkpoint for the "Dashboard Tenant improvement" task, specified by the audit
"Audit & Rekomendasi Widget Dashboard Tenant — OptiFleet-v2" (widget IDs are kept for traceability).

- **Baseline:** `origin/main` @ `93161f7` (same SHA as the audit — no drift on `main`).
- **Branch:** `claude/magical-volta-tv4xwl` (session-designated). Synced with `main` by a no-op merge
  (trees identical); no force-push.

## Revalidation against the code (differences from the audit)

| Topic | Audit assumption | Verified fact | Consequence |
|---|---|---|---|
| External WO invoice due date | `vendor_invoice_date + payment_term` | `payment_term` is free text (`max:255`) | FN-04 puts it in the "no structured due date" bucket (decision 7) |
| Vehicle document lifecycle | Use latest per type | No superseded/lifecycle state; `has_expiry`/`expiry_date` and `needs_extension`/`extension_deadline` are separate | Active = latest `issue_date` per vehicle+type (decision 8); expiry and extension deadline shown separately |
| Tire "due replacement" | Use structured decision | Installed-tire inspections only have free-text `recommendation`; structured decisions exist for used-tire inspections; rule profiles hold `d_pull_mm` | TR-02 = installed tires with latest tread ≤ D_pull + used-tire inspections awaiting finalization (decision 6) |
| Stock reservations | `quantity_reserved` KPI | Inventory Reservation retired (owner decision 2026-10-01); nothing writes `quantity_reserved` | No "reserved" KPI; availability stays `on_hand − reserved` (consistent with the app) |
| Vendor invoice | partial payments | One payment per invoice (paid at most once); statuses RECEIVED/VERIFIED/DISPUTED | Outstanding = amount − paid |
| WO access | — | App-wide WO list/detail use workshop scope | Dashboard follows the same rule (decision 4) |

## Owner decisions (approved in this session)

1. **Chart library / API:** Recharts; per-widget endpoints (`/app/dashboard/catalog`,
   `/app/dashboard/widgets/{ID}`, drill-down per widget) with independent loading/error/retry/cache.
2. **Finance permission:** new `dashboard.finance.view` (FN-01/02/03, FN-05, FN-06, PR-03, WH-04/05
   values). Seeded to Tenant Admin, Branch Admin, Auditor; existing tenants: granted by migration to
   the roles that hold `analytics.cost.view`. FN-04 uses the existing per-source invoice permissions.
3. **Service Cost rules:** document values including tax; parts = consumed cost recognized on WO
   `completed_at` (COMPLETED/CLOSED); retread/repair excluded from FN-01 (shown in TR-04).
4. **WO scope:** keep the existing workshop scope (no access-policy change). Branch attribution
   (`work_orders.branch_id`) is used only for grouping inside the accessible WOs.
5. **Aging thresholds:** fixed defaults in code — WS-02 0–3/4–7/8–14/15–30/>30 days (green ≤7,
   amber 8–14, red >14); FN-04 not due/1–30/31–60/61–90/>90/no due date; FL-06 overdue/≤30/31–60/61–90.
6. **TR-02:** installed tire latest inspection tread ≤ applicable D_pull + used-tire inspections
   with a structured recommendation not yet finalized. No text search.
7. **External WO due date:** no parsing; "no structured due date" bucket with the term text in drill-down.
8. **FL-06:** active document = latest `issue_date` (then `created_at`) per vehicle + type.

## Checkpoints

| # | Checkpoint | Status | Commit |
|---|---|---|---|
| 1 | Revalidation, scope, decisions | DONE | `83e9909` |
| 2 | Dashboard security + API contract | DONE | `1cec9ee` |
| 3 | UI foundation + current-state widgets | DONE | `48d179c` |
| 4 | Service cost, payables, reconciliation | DONE | `c6a42e4` |
| 5 | Trends, alerts, supporting widgets | DONE | `5c40eeb` |
| 6 | Seeders, bilingual, visual QA, regression | DONE | (CP6 commit on this branch) |

## Widget matrix (all K1/K2 widgets implemented; K3/K4 not implemented)

Endpoint for every widget: `GET /api/v1/app/dashboard/widgets/{ID}`; drill-down:
`GET /api/v1/app/dashboard/widgets/{ID}/details` (same permission, module, tenant and scope rules).
Kind `current` = situation now (not period-filtered); `period` = follows the trend-period filter.

| ID | Kind | Unit | Module | Permission (server-side) | Class |
|---|---|---|---|---|---|
| AL-01 | current | count | per source | each source widget's own permission/module/scope | ActionCenterWidget |
| FL-01 | current | count | VEHICLE | vehicle.view | FleetStatusWidget |
| FL-02 | current | count | VEHICLE | vehicle.view | FleetByBranchWidget |
| FL-03 | current | mixed | MAINTENANCE | breakdown.view | ActiveBreakdownsWidget |
| FL-04 | period | count | MAINTENANCE | breakdown.view | BreakdownTrendWidget |
| FL-05 | period | count | MAINTENANCE | breakdown.view | TopBreakdownVehiclesWidget |
| FL-06 | current | count | VEHICLE | vehicle.view | VehicleDocumentsWidget |
| FN-01 | period | money | WORK_ORDER | dashboard.finance.view | ServiceCostMonthlyWidget |
| FN-02 | period | money | WORK_ORDER | dashboard.finance.view | ServiceCostByVehicleWidget |
| FN-03 | period | money | WORK_ORDER | dashboard.finance.view | ServiceCostByBranchWidget |
| FN-04 | current | money | per source | per source: `vendor_invoice.view` (PROCUREMENT), `workshop_invoice.view` / `external_work_order_invoice.view` (WORK_ORDER) | PayablesAgingWidget |
| FN-05 | current | money | INVENTORY | dashboard.finance.view | InventoryValueWidget |
| FN-06 | period | money | PROCUREMENT | dashboard.finance.view | VendorRefundsWidget |
| MT-01 | current | count | MAINTENANCE | maintenance_schedule.view | ScheduleStatusWidget |
| MT-02 | current | count | MAINTENANCE | maintenance_schedule.view | OverdueMaintenanceWidget |
| MT-03 | current | count | MAINTENANCE | maintenance_request.view | OpenRequestsWidget |
| PR-01 | current | count | PROCUREMENT | (purchase_request.view or purchase_order.view) | ProcurementPipelineWidget |
| PR-02 | current | count | PROCUREMENT | purchase_order.view | LatePurchaseOrdersWidget |
| PR-03 | period | money | PROCUREMENT | purchase_order.view + dashboard.finance.view | PurchaseOrderValueWidget |
| PR-04 | period | mixed | PROCUREMENT | purchase_order.view | VendorPerformanceWidget |
| TR-01 | current | count | TIRE | tire.view | TireStatusWidget |
| TR-02 | current | count | TIRE | tire.view | TiresDueReplacementWidget |
| TR-03 | current | count | TIRE | tire.view | TiresAtVendorWidget |
| TR-04 | period | money | TIRE | tire.view + dashboard.finance.view | TireServiceCostWidget |
| WH-01 | current | count | INVENTORY | inventory.view | StockHealthWidget |
| WH-02 | current | count | INVENTORY | inventory.view | CriticalStockWidget |
| WH-03 | current | count | INVENTORY | stock_transfer.view | StockTransfersWidget |
| WH-04 | period | money | INVENTORY | inventory.view + dashboard.finance.view | StockMovementWidget |
| WH-05 | current | money | INVENTORY | inventory.view + dashboard.finance.view | SlowMovingStockWidget |
| WS-01 | current | count | WORK_ORDER | work_order.view | WorkOrderBacklogWidget |
| WS-02 | current | count | WORK_ORDER | work_order.view | OpenWorkOrderAgingWidget |
| WS-03 | period | count | WORK_ORDER | work_order.view | CompletedWorkOrdersWidget |
| WS-04 | period | mixed | WORK_ORDER | work_order.view | WorkOrderTurnaroundWidget |
| WS-05 | current | count | WORKSHOP | workspace.view | WorkspaceOccupancyWidget |
| WS-06 | current | count | WORK_ORDER | work_order.view | WaitingPartsWidget |

Tests: `backend/tests/Feature/Dashboard/` — Security (8), CurrentWidgets (6), FinanceWidgets (4),
TrendWidgets (6), DemoSeeder (1); plus `DashboardSupplyChainTest` (legacy endpoint) and
`frontend/tests/unit/dashboard.test.ts` (EN/ID keys for all 35 widgets).

## Service Cost (FN-01 / FN-02 / FN-03 — one query, `ServiceCostQuery`)

Base currency only (`DASHBOARD_BASE_CURRENCY`, default IDR); other currencies are reported as a
limitation, never summed or converted. Three disjoint sources, each document counted once:

| Source | Value | Recognition date | Included |
|---|---|---|---|
| PARTS | per line `round(consumed × round(issue total_cost / issued, 4), 2)` | WO `completed_at` | WO COMPLETED / CLOSED |
| EXTERNAL_SERVICE | Service Invoice `total_amount` (document value incl. tax) | `invoice_date` | status ≠ CANCELLED |
| EXTERNAL_WO | External WO invoice `vendor_invoice_amount` | `vendor_invoice_date` | BILLED / PAID |

Not counted: estimates, payments (a payment never adds cost), memos without an invoice (shown as
"not yet invoiced"), parts on open WOs ("not yet recognized"), tire/component purchases, retread and
repair (TR-04), internal labor (no labor cost is recorded — stated in the widget). Months in the
tenant time zone; empty months shown as 0; running month marked. Reconciliation: Σ months (FN-01) =
FN-02 total (incl. "other") = FN-03 total = Σ drill-down transactions (tests + demo DB check:
IDR 27,774,380.00 on the demo data).

## Demo seeder (`DashboardDemoSeeder`, demo layer only)

Registered in `DevDemoSeeder::seedDemoLayer` (runs only in `APP_ENV=local` or with `SEED_DEMO_DATA=true`); production
seeding only adds the `dashboard.finance.view` permission (`PermissionSeeder` + migration).
ALPHA tenant, 3 dedicated vehicles (Jakarta / Bandung / Semarang, each serviced at its own branch
workshop), 12 full months + running month with one empty month (7 months ago), all through
application services under a controlled clock (`Carbon::setTestNow`; a caller's frozen clock is
respected and restored). Scenarios: WO completed / waiting-part / cancelled / external (billed,
settled); vendor invoices paid / not due / overdue; Service Invoices paid / unpaid /
cancelled-by-maker-checker and re-recorded; resolved breakdowns + one open immobilized; transfer in
transit 10 days; low stock. Other demo seeders supply transfers with discrepancy, expired / due
documents, retread cycles open / received, FTEST second tenant. Idempotent (marker `[DASH-DEMO]`).
Verified on a disposable DB: `migrate:fresh --seed` then `db:seed` → identical row counts; no
cross-tenant references; no negative stock; FN-01 = FN-03.

## Validation (CP6, executed in this session)

| Check | Result |
|---|---|
| Backend full regression (non-Mongo; Analytics/Intelligence set aside) | PASS — 1226 tests, 21790 assertions |
| Dashboard + seeder + permission tests on final code | PASS — 65 tests, 715 assertions |
| MongoDB (Analytics / Intelligence) tests | NOT RUN — no MongoDB in this environment |
| Frontend `tsc -b`, `npm run build` | PASS |
| Frontend `oxlint` | PASS (0 errors; warnings of rule classes already present in the codebase) |
| Frontend unit tests | PASS — 52 |
| `i18n:check` / `i18n:audit` | PASS (up to date) / no untranslated dashboard UI text |
| Demo seed fresh + rerun on disposable DB | PASS — identical counts, 0 cross-tenant refs |
| Visual QA 1440 / 820 / 390 px, EN + ID, tenant-wide and branch-scoped user, drill-downs | PASS — no console/page errors from the dashboard; empty states only where data is legitimately empty |

## Known limitations (documented, not estimated)

- Partially paid invoices cannot be seeded or shown: every invoice source allows exactly one full
  payment.
- Pending vendor refunds have no amount (count only); adjustments/scrap have no unit cost and stock
  opname has no direction (WH-04 limitations); WS-04 is "turnaround", not MTTR.
- Analytics (MongoDB) cost definitions differ and were not changed; MongoDB tests NOT RUN.
- The "Desain Dashboard UI UX" chat was not accessible; the existing design system was used.
- Not implemented by scope (K3/K4): availability/utilization, cost/km, PM compliance, actual labor,
  inventory value trend, ROI/savings/budget, MTBF/MTTR, Intelligence, Warranty.

## Deployment

1. `php artisan migrate` (adds and grants `dashboard.finance.view` to roles holding
   `analytics.cost.view`; idempotent). Alternatively `php artisan db:seed --class=PermissionSeeder`.
2. Frontend: `npm ci && npm run build` (new dependency `recharts`).
3. Optional (`DASHBOARD_BASE_CURRENCY`, default `IDR`). No MongoDB dependency; cache uses the default
   cache store (TTL 120 s current / 300 s period; per tenant, scope, permissions, filters, version).
4. Demo only: `SEED_DEMO_DATA=true php artisan db:seed`.
