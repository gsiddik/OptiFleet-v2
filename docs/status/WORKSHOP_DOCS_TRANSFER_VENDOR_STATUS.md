# Workshop Invoice Cleanup, External Workshop Documents, Stock Transfer, File Preview, QC, Vendor Performance, Menu Consolidation — Status

Branch `claude/magical-volta-tv4xwl`, baseline `main` @ 24baa28.

## Phase 1 — Dependency audit

There are **two different invoice domains** — the request's "Workshop Invoices" is the first:

| Domain | Model / table | What it is | Entry points |
|---|---|---|---|
| Workshop Invoice (R1) | `WorkshopInvoice` (+ corrections, cancellations, payments) | Invoice a partner issues for an **External Service / Maintenance Memo** (towing, 3rd-party job) on an otherwise **internal** Work Order; maker-checker corrections/cancellations, payment → memo BILLED → PAID | Menu "Workshop Invoices" → list page → detail page; recorded from WO → External Services tab ("Record Workshop Invoice"), which links to the detail page |
| External Work Order Invoice | `WorkOrderExternalInvoice` (+ `work_order_external_invoice_files`) | Lifecycle of a Work Order executed by an **External Workshop**: WAL generate → deliver → acknowledge (signed WAL upload) → complete (completed-WO file, vendor invoice file + date + amount) → settle (payment proof + date + amount) | Menu "External Work Order Invoices" (list with action matrix, Bill popup) |

Classification:

| Item | Classification |
|---|---|
| "Workshop Invoices" sidebar menu + list page (`/app/workshop-invoices`) | **REMOVE** (standalone entry point) |
| Workshop Invoice detail page (payment, correction, cancellation, print, reconciliation) | **REFACTOR / MOVE** — kept as a Work Order sub-page, reached from WO → External Services (internal WOs); back navigation to its Work Order |
| `WorkshopInvoice*` models, service, tables, routes for record/payment/correction/cancellation/print, permissions `workshop_invoice.*`, integration outbox, document template | **RETAIN** (used by the WO External Services flow on internal WOs, reconciliation, print, audit) |
| `GET /workshop-invoices` index API | **RETAIN** (API, harmless; no UI caller after removal) |
| Historical workshop invoices / payments | **HISTORICAL ONLY** — nothing deleted |
| `WorkOrderExternalInvoice` domain + files + permissions | **RETAIN** — source of the WO Documents tab (WAL / invoice / payment proof) |

External Services tab: `WorkOrderDetailPage` shows it for both execution modes
(`EXTERNAL_MODE_TABS` explicitly includes it). → hide for `execution_mode = EXTERNAL`
(all statuses); internal WOs unchanged; backend memo capability retained.

Documents tab: currently an empty placeholder ("not tracked in this phase").

Files (`work_order_external_invoice_files`: disk, path, original_filename, mime_type, size,
role): roles ACKNOWLEDGEMENT (doc/docx/pdf), COMPLETED_WORK_ORDER / VENDOR_INVOICE /
PAYMENT_PROOF (jpg/jpeg/png/pdf). Backend serves via `Storage::response()` (content-type from
the file) under tenant + workshop scope. **Bug:** the frontend `openPdf()` re-wraps every blob
as `application/pdf` → PNG/JPG attachments open as a broken PDF (Bill popup).

Adjustment: the "Adjustment" menu item is only a second link to `/app/inventory`
(Warehouse Stock); the adjust action already lives there (`inventory.adjust`, `POST
/app/inventory/adjust`). No standalone page/workflow. → **REMOVE** menu item only; service,
ledger, audit, permission **RETAIN**.

Quality Control: the menu item is a link to `/app/work-orders?status=QC_PENDING`; QC is
performed in WO → QC tab (`qc.perform`, `qc.approve`, `qc.reject`); `qc.view` guards
`GET /qc-inspections`. → **REMOVE** menu item only; QC domain, routes, permissions **RETAIN**.

Supplier: `SupplierListPage` is `PartnerListView` filtered to partner types SUPPLIER /
SPARE_PART_SUPPLIER / TIRE_SUPPLIER — same `partners` table, same API, same FKs (PO, RFQ, GR,
invoices use `partner_id`). → **REMOVE** menu; `/app/suppliers` redirects to Vendors filtered
to suppliers; Vendors page gains a type filter. "Vendor Performance" menu is a duplicate link
to `/app/partners` → removed (performance lives on Vendor Detail).

Stock Transfer: statuses DRAFT → REQUESTED → APPROVED → PREPARED → DISPATCHED → IN_TRANSIT →
RECEIVED (→ COMPLETED). Item semantics: `quantity_received` = good units put into destination
stock; damaged and lost are separate; rule `received + damaged + lost ≤ sent`, shortfall needs
a discrepancy reason (already enforced). Who/when is stored only for dispatch and receive;
every status change is in `audit_logs` (StockTransfer is `Auditable`, actor = acting user).

Vendor performance: one generic summary over `partner_performance_events` for all types.

Money: several raw `decimal:4` values rendered/pre-filled without `formatMoney` (e.g. Workshop
Invoice reconciliation and payable amount, correction / settlement pre-fills).

## Phase 2 — External Workshop Work Order: tabs and Documents (DONE)

- External Services tab hidden for `execution_mode = EXTERNAL` in every status (decided by
  execution mode, not status); internal Work Orders keep it. The backend memo capability is
  unchanged.
- `GET /work-orders/{id}/documents` (`work_order.view` + tenant/workshop scope): for an External
  Workshop WO lists **Work Authorization Letter** (only when `work_authorization_status =
  ACKNOWLEDGED`; upload time of the signed WAL; WAL number), **External Workshop Invoice**
  (the invoice's own `vendor_invoice_date`, amount) and **Payment Proof** (only once settled;
  `payment_date`, paid amount) with file metadata (name, MIME, size). Files open through the
  existing External Work Order Invoice endpoints (`external_work_order_invoice.view`, tenant +
  workshop scope); no storage path is exposed.
- `components/DocumentViewer`: opens a protected file by the Content-Type the backend actually
  served — image → inline preview, PDF → embedded viewer + new tab, other (e.g. .docx WAL) →
  download; download always available.
- Tests: `WorkOrderDocumentsTest` (2: lifecycle gating, real PNG/JPG/PDF content types,
  internal WO, tenant/permission) + related WO/external/workshop-invoice suites — 113 passed.
  Browser: External WOs (NEW / DELIVERED / BILLED / CLOSED) have no External Services tab;
  documents appear per stage; PNG proof previews as image, PDF invoice in PDF viewer; internal
  WO still shows External Services.

## Phase 3 — File handling & money formatting (DONE)

- External Work Order Invoice list (incl. the Bill popup): removed `openPdf()`, which re-wrapped
  every blob as `application/pdf`; all View/Open actions (WAL, acknowledgement, completed WO,
  vendor invoice, payment proof) use `DocumentViewer` → PNG/JPG preview as image, PDF in the PDF
  viewer, other types download. Content type is never faked.
- `fetchProtectedFile()` returns blob + server filename (Content-Disposition); `config/cors.php`
  published with `exposed_headers = [Content-Disposition]` so the browser can read it
  cross-origin (download keeps the original filename).
- Money display: shared `formatMoney` (max 2 decimals) now used for WO overview labor / parts /
  total and job estimate, Workshop Invoice reconciliation + payable, Tire Intelligence cost/km,
  Dashboard inventory value, platform invoice / billing / payment amounts. New helpers
  `toMoneyInput` (form pre-fill without trailing `.0000`) and `sumMoney` (BigInt, no float).
  Storage precision unchanged. Exception: the raw JSON in the audit-log diff view.
- Tests: `WorkOrderDocumentsTest` (3, incl. exposed header) + WorkshopInvoice /
  ExternalWorkOrderInvoice suites — 42 passed. Frontend build PASS; lint identical to baseline.
  Browser crawl of 50 tenant/platform pages: 0 amounts rendered with 3–4 decimals.

## Phase 4 — Stock Transfer traceability (DONE)

- IN_TRANSIT receive form: every input has a visible label — **Received Qty** (hint: good units
  into stock), **Damaged Qty**, **Lost Qty**, **Discrepancy Reason** (hint: required if received
  is less than sent). The received pre-fill no longer shows `4.0000`.
- `GET /stock-transfers/{id}` adds `status_history` (`status`, `at`, `by` = user name) built from
  the transfer's audit trail (`audit_logs`, tenant-scoped; the actor of each create/update), not
  from `updated_at`, so Draft / Requested / Approved / Prepared / Dispatched / In Transit /
  Received each keep their own time and user. Same-second steps are ordered by lifecycle
  position. Also `dispatched_by_name` / `received_by_name`; the page shows names, never user
  IDs. Existing fields (`dispatched_by`, `received_by` IDs) kept for backward compatibility.
- RECEIVED / COMPLETED transfers show an items table: Sent, Received, Damaged, Lost,
  Discrepancy Reason.
- Quantity semantics unchanged (received = good units into stock; received + damaged + lost ≤
  sent; shortfall needs a reason — enforced in `StockTransferService::receive`).
- Tests: `StockTransferTest` 7 passed (new: history statuses + two distinct actor names,
  dispatched/received names, damaged/lost/reason in the detail payload). Browser: labels,
  history and receipt table verified on an Alpha transfer; no UUIDs visible.

## Phase 5 — Menu consolidation (DONE)

| Menu item | Result | What stays |
|---|---|---|
| Maintenance → Workshop Invoices | menu + list page removed; `/app/workshop-invoices` → `/app/work-orders` | detail page `/app/workshop-invoices/:id` (reached from WO → External Services; back / fallback → its Work Order, WO number links to it); all APIs, permissions, data |
| Maintenance → Quality Control | menu removed | WO → QC tab, `qc.*` permissions and routes |
| Inventory → Adjustment | menu removed | Warehouse Stock → Adjust action (`inventory.adjust`, backend-authorized) |
| Partner → Vendor Performance | menu removed (duplicate link to Vendors) | performance on Vendor Detail |
| Partner → Suppliers | menu removed; `/app/suppliers` → `/app/partners?type=SUPPLIERS` | Vendors page gains a **Type** filter (All types / All Suppliers / each type, kept in the URL); "+ New Vendor" defaults to the filtered type |

No permission was removed (each was also a guard elsewhere: `qc.view` on `/qc-inspections`,
`inventory.adjust` on the adjust action, `workshop_invoice.view` on the detail page and APIs). The
removed QC / Adjustment links pointed at pages that already need `work_order.view` /
`inventory.view`, so no user loses access. User guide navigation updated.

Validation: frontend build PASS, lint identical to baseline. Browser (Alpha and FT admins): 58
menu links, none of the removed items; sidebar search "supplier" / "adjust" / "quality" → no
results, "invoice" → External Work Order Invoices + Vendor Invoice Reference; `/app/suppliers`
→ supplier types only; EXTERNAL_WORKSHOP and All filters correct; `/app/workshop-invoices` →
Work Orders; Workshop Invoice detail opens with "Back to Work Order"; Warehouse Stock adjust
actions present.

## Phase 6 — Type-aware Vendor Performance (DONE)

`GET /partners/{id}/performance?from=YYYY-MM-DD&to=YYYY-MM-DD` (`partner.view`, tenant-scoped,
`to ≥ from`; default = last 12 months to today). `VendorPerformanceService` computes KPIs from the
operational tables with one aggregate query per block (no N+1); money summed as SQL `numeric` and
returned as 2-decimal strings. The period selects a cohort by its start event and is returned
with its basis:

| Category (partner types) | Cohort | KPIs |
|---|---|---|
| EXTERNAL_WORKSHOP | WOs whose WAL was issued to the workshop in the period | assigned, acknowledged, completed, cancelled, rejected (*), ack / completion / cancellation rate, avg ack time (delivered → acknowledged), avg completion time (acknowledged → completed), invoice / paid / outstanding amount |
| SUPPLIER, SPARE_PART_SUPPLIER, TIRE_SUPPLIER | POs ordered in the period | POs issued / fully received / cancelled, PO value, deliveries, on-time vs late (first posted GR vs expected date), on-time rate, avg lead time, qty accepted / rejected / damaged, rejection rate, invoice / paid / outstanding |
| TOWING_PROVIDER, OTHER_SERVICE_PROVIDER | External services requested in the period | requested, completed, cancelled, rates, avg completion time, estimated cost, workshop invoice / paid / outstanding |

(*) The External Workshop workflow has no "rejected by workshop" state (WAL statuses
NOT_GENERATED / GENERATED / ACKNOWLEDGED; a declined job is cancelled), so `rejected` is `null`
and shown as "—" with an explanation — not invented.

The legacy `performance` block on `GET /partners/{id}` (event-log based) is kept for backward
compatibility; Vendor Detail now shows the type-aware card with a From / To period selector.

Tests: `VendorPerformanceTest` (4: exact counts / rates / durations / amounts per category with
noise from another partner, another tenant and outside the period; default period, zero state,
422 on inverted period, 404 cross-tenant, 403 without `partner.view`) + Partner / Rewired
permissions suites — 19 passed. Browser: FT external workshop, spare-part supplier and towing
provider each show their own KPI set; a 2020 period shows zeros.

## Phase 7 — Seeders, regression, release gate (DONE)

- Seeder: Alpha demo data adds one Jakarta → Bandung oil-filter transfer driven through
  `StockTransferService` by the tenant admin (Draft → Requested → Approved) and the Bandung
  warehouse manager (Prepared → Dispatched → In Transit → Received), spread over two days, received
  2 / damaged 1 / lost 1 with a reason. Idempotent (notes marker).
- Seed check (fresh migrate + DatabaseSeeder + DevDemoSeeder + FunctionalTestingSeeder, twice):
  no errors, identical row counts on both passes, one demo transfer with a 7-step history and two
  distinct user names.
- Backend full regression (PostgreSQL): **967 passed (5170 assertions)**. MongoDB-dependent
  Analytics / Intelligence suites: **NOT RUN** (no MongoDB in this environment); their migrations
  and tests are untouched.
- Frontend: build PASS; oxlint findings identical to the pre-change baseline (28, all pre-existing).

## API contract changes (all additive)

| Endpoint | Change |
|---|---|
| `GET /work-orders/{id}/documents` | new (`work_order.view`) |
| `GET /stock-transfers/{id}` | adds `status_history`, `dispatched_by_name`, `received_by_name` |
| `GET /partners/{id}/performance` | new (`partner.view`) |
| CORS | exposes `Content-Disposition` |

No endpoint, field or permission was removed or renamed; no migration was added.

## DECISION REQUIRED — Workshop Invoice (R1) future

- **Current architecture:** two invoice domains. R1 Workshop Invoice = vendor invoice for an
  External Service / Maintenance Memo (towing, third-party job) on an internally executed WO,
  with maker-checker correction / cancellation, payment and reconciliation. The External Workshop
  invoice belongs to `WorkOrderExternalInvoice` (WO executed by an External Workshop).
- **Dependency:** WO → External Services tab (internal WOs), memo status BILLED → PAID, workshop
  invoice permissions, print template, integration outbox, service-provider vendor KPIs.
- **Impact if deleted:** internal WOs could no longer record or pay third-party service invoices;
  historical invoices and payments would become unreachable.
- **Implemented option:** the standalone menu / list was removed; the domain is kept and reached
  only from its Work Order (detail page with back link to the WO). Nothing deleted.
- **Alternative:** fold R1 into the External Work Order Invoice list as a second "service
  invoice" type — a behavioral and UX change needing product sign-off.
- **Recommendation:** keep the implemented option; revisit only if third-party services on
  internal WOs should move to the External Workshop flow.
- **Risk:** low — users who used the old list now start from the Work Order; `/app/workshop-invoices`
  redirects to Work Orders.

## Owner decision applied — Service Invoice terminology (DONE)

Decision: keep the current architecture. The legacy Workshop Invoice (third-party services such
as towing on an **internally executed** WO, with correction / cancellation / payment /
reconciliation) is **not** merged into the External Work Order Invoice list (WOs executed by an
External Workshop). Standalone menu / list stays removed; access only from the Work Order;
`/app/workshop-invoices` keeps redirecting to Work Orders; tables, history, model / service / API
retained. External Workshop WOs still never show External Services.

User-facing terminology (no DB / domain / route / permission-key rename, no migration):

| Where | Before | After |
|---|---|---|
| WO → External Services buttons, record modal | Record / View Workshop Invoice | Record / View **Service Invoice** |
| Detail page title, partner label, breadcrumb | Workshop Invoice — …, Workshop Partner, Workshop Invoices | **Service Invoice** — …, **Service Provider**, **Service Invoices** |
| External Work Order Invoices list title / cancel text | "Workshop Invoice" | "External Work Order Invoices" / "External Workshop Invoice" |
| Backend validation / scope messages | "… Workshop Invoice …" | "… Service Invoice …" |
| Role management labels (`PermissionCatalog`) | feature "Workshop Invoice"; action "View Workshop Invoice Reference" | "Service Invoice"; "View External Workshop Invoice Reference" (keys unchanged) |
| User guide | Workshop Invoice | Service Invoice + terminology note; glossary keeps the old name |

Not changed on purpose: the `workshop_invoice` print-template default ("Recorded Workshop
Invoice") — published templates are versioned per tenant and never overwritten by the seeder, so
changing only the seed default would make fresh installs differ from existing tenants; tenants can
retitle it in Configuration → Document Templates.

Validation: related backend suites (WorkOrder*, WorkshopInvoice*, ExternalWorkOrder*, Role /
Permission / Rewired, Partner, VendorPerformance) — 196 passed (new assertions: catalog labels with
unchanged keys; error message uses "Service Invoice"). Frontend build PASS, lint identical to
baseline. Browser (FT tenant, rebuilt e2e data): internal WO → External Services → View Service
Invoice opens the historical invoice with its payment, back returns to the WO; three External
Workshop WOs (NEW / DELIVERED / BILLED) show no External Services tab; `/app/workshop-invoices` →
`/app/work-orders`; no invoice menu in the sidebar.
