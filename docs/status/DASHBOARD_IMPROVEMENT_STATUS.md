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
| 1 | Revalidation, scope, decisions | DONE | this commit |
| 2 | Dashboard security + API contract | TODO | |
| 3 | UI foundation + current-state widgets | TODO | |
| 4 | Service cost, payables, reconciliation | TODO | |
| 5 | Trends, alerts, supporting widgets | TODO | |
| 6 | Seeders, bilingual, visual QA, regression | TODO | |
