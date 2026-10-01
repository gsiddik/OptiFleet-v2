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
