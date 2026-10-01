# Sidebar UX, Upload Reliability, Goods Receipt → Vendor Invoice → Payment — Status

Branch `claude/magical-volta-tv4xwl`, baseline `main` @ 586de40.

## Phase 1 — Audit (main @ 586de40)

**Sidebar** (`layouts/TenantLayout.tsx`): static `NAV_GROUPS` (group label + items, each with
`permission` + `module`), filtered client-side by `hasPermission` and the tenant's active
modules (backend still enforces both on every route). Every group is always fully expanded;
there is no search, no minimize, no icons (the project has no icon library — only
react/react-router/axios). The `<aside>` is not sticky: on desktop it grows with the page, so a
long menu makes the whole page long. Mobile (≤768px) already uses an off-canvas drawer.
Logo: `Logo` component (`object-fit: contain`) at height 40 inside an inline white card; the
512×188 asset has ~30/47px side padding baked in, so the logo renders ~110px wide in a 230px
sidebar with large empty space. A square mark exists (`apple-touch-icon.png`).

**Purchase Order / Goods Receipt**: `GoodsReceiptService::post` is already one DB transaction:
PO row lock, server-generated tenant-aware `gr_number` via `DocumentNumberingService`
(`unique(tenant_id, gr_number)`), over-receipt rejected per line (cumulative via
`quantity_received`), stock posted through `InventoryService::receive`, PO status derived from
remaining quantities (ISSUED → PARTIALLY_RECEIVED → RECEIVED). Every post creates a new GR;
`received_at` is the explicit receipt timestamp. PO Detail has a receive form but no receipt
history and no invoice capture.

**Vendor Invoice Reference**: table `vendor_invoice_references` (partner, nullable PO, nullable
single `goods_receipt_id`, number, date, amount, private attachment, status
RECEIVED/VERIFIED/DISPUTED). Standalone create page (vendor picker, no file sent although the
backend accepts one), Verify/Dispute actions. No terms of payment, due date, payment, or
uniqueness of vendor + invoice number. One invoice can link to only one GR.

**Payment**: no vendor-invoice payment exists. (`payments` / `account.payment.*` is the SaaS
billing domain — tenant paying OptiFleet — and stays separate.)

**Uploads** (all private `local` disk, UUID names, tenant-scoped download endpoints):
- Single-request multipart flows persist correctly (RFQ quotation, external WO invoice files,
  vehicle documents/photo, product image/SDS, WO evidence, used-part evidence, company logo).
- **Defect — SaaS Submit Payment**: payment is created first, proof uploaded in a 2nd request.
  If the proof is rejected (content type/size) the payment already exists, and correcting the
  file and pressing Submit again creates a **duplicate payment**.
- **Defect — Vehicle Brand (new)**: brand created first, logo uploaded second; a failed logo
  upload leaves the brand without a logo and a retry fails on the duplicate code.
- **Defect — Vendor Invoice Reference create**: no file field at all; nothing uploaded.
- File stored before the DB row in several services without cleanup on DB failure (orphans).

**Working days / configuration**: `MaintenancePolicy\WorkingDayService` exists (schedule
date shifting); no business-day addition and no holiday calendar. The configuration framework
covers numbering/templates/workflows/notifications only — no general settings store.

**Permissions**: `goods_receipt.{view,create,post}`, `purchase_order.*`; no vendor-invoice or
vendor-payment permissions (the invoice page reuses `goods_receipt.view/create`).

## Phase 2 — Sidebar (DONE)

- `layouts/tenantNav.ts`: menu config (unchanged routes / permissions / modules) + group icon and
  `searchNav`. `layouts/TenantSidebar.tsx`: new sidebar; `components/NavIcon.tsx`: inline SVG
  icon set (no icon library in the project; no emoji).
- Search: case-insensitive over group and item labels, applied after permission / module
  filtering; a matching child keeps its parent group visible; group-name match shows the
  group; Esc clears; "No menu matches" empty state.
- Groups collapse / expand (aria-expanded); the group of the current route opens by default;
  user choices remembered for the browser session (sessionStorage, guarded).
- Height: sticky 100vh column, menu scrolls internally; page height no longer grows with the
  menu; last item always reachable.
- Minimize (64px) / maximize (240px) via the footer toggle, remembered (localStorage, guarded).
  Minimized: group icons with tooltip + hover/focus flyout of the group's menu; active group
  highlighted; search becomes an icon that maximizes and focuses the search box. Mobile keeps
  the off-canvas drawer, always expanded.
- Logo: full-width white card; default wordmark framed to its artwork (transparent margins
  only fall outside the frame — nothing of the artwork is cropped, aspect ratio kept); tenant
  logo uses `object-fit: contain`; minimized shows the square mark.
- Verified in the browser (desktop 1440, tablet 1024, mobile 390): search "INVOICE" → Workshop
  Invoices, External Work Order Invoices, Vendor Invoice Reference under their parents;
  expand/collapse; all groups expanded → nav scrolls, page height unchanged; minimize → main
  content shifts to x=64, flyout navigation works, state persists across reload.

## Phase 3 — Upload foundation (DONE)

- `Shared\Services\PrivateDocumentStorage`: content-sniffed type + size check (authoritative,
  after the request `mimes`/`max` rules), private `local` disk, tenant directory, UUID name;
  `persist()` stores the file(s) then runs the DB work in one transaction and deletes the
  stored files if anything fails (no orphan file, no record pointing at a missing file).
- Fixed — SaaS Submit Payment: `POST /app/account/payments` now accepts the optional proof
  (`file`) in the same multipart request; payment + proof are saved atomically. A rejected
  proof saves nothing, so correcting it and resubmitting creates exactly one payment.
  `POST /payments/{id}/proof` unchanged (adding proofs later), now also orphan-safe.
- Fixed — Vehicle Brand (new): a brand created on an attempt whose logo upload failed is
  updated on retry (PUT + logo) instead of re-created; closing the dialog refreshes the list.
- Frontend: reusable `components/FileUploadField` (choose / replace / remove before submit,
  client pre-check, existing-document "View"), `utils/fileRules`, `utils/protectedFile`
  (open/download via authorized API — documents are never public URLs).
- Tests: `DocumentUploadPersistenceTest` (4) + Payment/Brand/Billing related — 22 passed.
  Browser: bad proof → 422 and no payment; corrected retry → one payment with `valid.png`;
  bad brand logo → error, retry → single brand with logo.

## Phase 4 — Goods Receipt + Vendor Invoice Reference (DONE)

- Migration `2026_10_02_000001` (additive): `goods_receipts.vendor_invoice_reference_id` (FK,
  restrict, indexed; many GRs → one invoice); `vendor_invoice_references` + `origin`
  (MANUAL | GOODS_RECEIPT, check), `terms_of_payment_days`, `due_date`, document metadata,
  `created_by`; partial unique index `(tenant_id, partner_id, UPPER(vendor_invoice_number))
  WHERE origin = 'GOODS_RECEIPT'`. Backfill: a legacy invoice that pointed at a GR is linked
  from that GR. Legacy rows keep origin MANUAL and are not constrained or deleted.
- `POST /purchase-orders/{po}/goods-receipts` (multipart) now requires the invoice:
  `invoice_mode` NEW (number trimmed, date, amount `^\d+(\.\d{1,2})?$` > 0, terms whole working
  days 0–3650, optional `invoice_document` PDF ≤ 10 MB, content-checked) or EXISTING
  (`vendor_invoice_reference_id` of an earlier receipt of the same PO — no new row, no new
  file). Vendor = the PO's vendor. Receipt, stock, PO status, invoice and file are one
  transaction (PDF removed if it fails). Duplicate vendor + number (case-insensitive) → 422
  (app check + DB index for races).
- Due date = invoice date + N working days (Mon–Fri) via `WorkingDayService::addBusinessDays`
  (existing working-day service extended); public holidays not counted (no holiday calendar).
- Standalone `POST /vendor-invoice-references` removed (GR is the only entry point); invoice
  document download now also checks the PO warehouse data scope and serves the original name.
- PO show returns the receipt history: every GR oldest first with items, receiver and invoice.
- Frontend: Post Goods Receipt → `RecordVendorInvoiceModal` (vendor read-only, number, date,
  Amount / Terms as text numeric inputs, PDF upload with replace/remove, "Use the same invoice
  as the previous Goods Receipt" showing the previous invoice + its document); one multipart
  request; double-submit guard. `GoodsReceiptHistory`: GR#, receipt date (`received_at`),
  received quantity, invoice number, View / Download, received by; RECEIVED shows "Goods
  received: <date> (completed in N receipts)". List page lost its "Record Invoice Reference".
- Tests: `GoodsReceiptVendorInvoiceTest` (7) + updated `ProcurementTest`; related regression
  108 passed. Browser: ISSUED PO → GR1 new invoice + PDF (jpg rejected client-side; replace /
  remove work) → PARTIALLY_RECEIVED → GR2 same invoice (previous PDF shown) → GR3 new invoice
  → RECEIVED; history 3 rows; download returns the original PDF.

## Phase 5 — Vendor Invoice References list (DONE)

- `GET /vendor-invoice-references` now lists **one row per Goods Receipt** received against an
  invoice (newest first): `{id (GR), gr_number, received_at, purchase_order {id, po_number},
  invoice {id, number, date, amount, terms_of_payment_days, due_date, has_document, partner,
  status}}`; filters `status`, `partner_id`, `purchase_order_id`, `search` (GR#, PO#, invoice
  number, vendor); tenant + warehouse data scope via the GR. A shared invoice shows the same
  invoice id / data / status on each of its rows. Legacy invoices never linked to a GR are
  kept (and readable via show) but not listed.
- Status (`Procurement\Support\VendorInvoiceStatus`, backend only): PAID (persisted fact,
  Phase 6) else LATE (today > due) / DUE_SOON (due within `procurement.invoice_due_soon_days`,
  default 7 calendar days, env `PROCUREMENT_INVOICE_DUE_SOON_DAYS`) / NEW; derived at read time
  in the tenant's time zone, so it never goes stale. Legacy invoices without a due date: NEW.
- New permission `vendor_invoice.view` (list, show, document download); migration
  `2026_10_02_000002` grants it to every role holding `goods_receipt.view`; seeders updated.
- Frontend list: GR# (+ "View Invoice"), Purchase Order # (link), Invoice Number, Vendor,
  Amount, Terms of Payment (Days, integer), Invoice Date, Due Date, Status badge; status
  filters + search; no "Record Invoice Reference"; Verify/Dispute actions removed from the UI
  (the legacy `/status` endpoint is retired with Phase 6).
- Tests: `VendorInvoiceReferenceListTest` (4) — related regression 72 passed. Browser: 3 GR
  rows (two sharing INV-E2E-A), filters, search, View Invoice.

## Phase 6 — Vendor invoice Payment (DONE)

- Table `vendor_invoice_payments` (migration `2026_10_02_000003`): tenant, invoice FK
  (restrict), **unique `vendor_invoice_reference_id`** (zero or one payment per invoice — a DB
  guarantee against double submit / races), payment_date, amount decimal(16,4), private proof
  (disk/path/original name/mime/size), paid_by, timestamps.
- Payment belongs to the invoice, not to a GR row: an invoice shared by several receipts is
  paid once and every one of its rows shows PAID (status PAID = a payment exists).
- `POST /vendor-invoice-references/{id}/payments` (`vendor_invoice.pay`, tenant + warehouse
  scope; multipart `payment_date`, `amount`, `payment_proof`): full settlement only (no
  partial-payment model exists) — amount must equal the invoice amount (BigDecimal compare),
  payment date not in the future (tenant time zone), proof JPG/JPEG/PNG/PDF ≤ 10 MB
  (content-checked). Invoice row lock + already-paid check + unique key; proof stored with
  the payment in one transaction and removed if it fails. Second payment → 422.
- `GET /vendor-invoice-references/{id}/payment-proof` (`vendor_invoice.view`): inline, original
  name and content type.
- Permissions: `vendor_invoice.pay` granted to every role that had `goods_receipt.create`
  (the former invoice-reference managers); `goods_receipt.create` had no remaining route and
  is retired (permission + grants removed, like `inventory.reserve`). The legacy
  RECEIVED/VERIFIED/DISPUTED `/status` endpoint is removed (no UI since Phase 5; stored values
  kept). Seeders updated.
- Frontend: Action "Payment" for NEW / DUE_SOON / LATE (with `vendor_invoice.pay`); popup with
  read-only Vendor Name + Invoice Number, Payment Date (≤ today), Amount (text numeric,
  pre-filled with the invoice amount), Payment Proof (choose / replace / remove);
  "Due Date / Payment Date" shows `due / paid` once paid, the payment date opens the proof
  (image preview, PDF open, download).
- Tests: `VendorInvoicePaymentTest` (6) — related regression 88 passed. Browser: shared
  INV-E2E-A paid from one row → both GR rows PAID, single payment; image proof previews and
  downloads; PDF proof opens; second payment via API → 422.

## Phase 7 — Seeders + final regression (DONE)

- `SupplyChainSeeder` (demo partial receipt 30/50) and `FunctionalTestInventorySeeder` (full
  receipt) now post their Goods Receipts against a vendor invoice (INV-SSC-2026-0001, 30
  working days; FT-INV-2026-0001, 14 working days) with a demo invoice PDF;
  `DemoQuotationDocument::make()` takes an optional title/file name for that. Permission and
  role seeders updated in Phases 5–6 (`vendor_invoice.view`, `vendor_invoice.pay`;
  `goods_receipt.create` retired).
- Seed check: fresh migrate + all seeders twice — identical counts, 2 GR-linked invoices with
  documents, permissions as intended, no errors.
- Full backend regression (PostgreSQL, Mongo-only migrations/tests set aside during the run):
  **956 passed, 0 failed**. Frontend `npm run build` PASS, `npm run lint` 0 errors (warnings
  identical to baseline); no typecheck/test scripts exist (`tsc -b` runs inside the build).
  Mongo-dependent tests: NOT RUN (no MongoDB here).
- Note: commit 3df6356 accidentally deleted three Mongo migrations (set aside locally for
  testing); restored byte-identical in 3de74ac. Tests now run through a wrapper that always
  restores them, and commits stage explicit paths only.

## Final owner decisions (applied)

1. **Payment = full settlement only.** Amount must equal the invoice amount (lower and higher
   both rejected, BigDecimal compare); second payment on a PAID invoice rejected (row lock +
   unique key); payment date in the future rejected. Partial payments / outstanding balances /
   multiple payments are out of scope (not built).
2. **Invoice PDF optional.** An invoice saves without a document; when supplied it must be a PDF
   (request `mimes:pdf` + server content check), is stored privately and stays viewable /
   downloadable after reload; replace / remove happen before submit in the popup.
3. **Due Soon** default 7 calendar days before the due date, single setting
   `procurement.invoice_due_soon_days` (env `PROCUREMENT_INVOICE_DUE_SOON_DAYS`); the frontend
   never computes status — the backend is the source of truth.
4. **Working days = Monday–Friday**; Saturday and Sunday skipped. Public holidays are not
   counted (OptiFleet has no holiday/business calendar; none hardcoded).
5. **A PAID invoice can never be reused.** `EXISTING` reuse requires: same tenant, invoice
   `purchase_order_id` = this PO, same vendor, already used by an earlier receipt of this PO,
   and **no payment**. Enforced in `VendorInvoiceReferenceService` under the invoice row lock
   (serialised with payment, which locks the same row); the PO detail exposes the invoice's
   payment, so the popup only offers an unpaid previous invoice and explains when it is paid.
6. **No invoice ↔ GR value matching.** Validations stay: amount numeric > 0 (≤ 2 decimals),
   vendor = PO vendor, invoice belongs to the PO, reuse lifecycle rule above.
7. **Goods Receipt contract** (accepted): every receipt carries invoice data —
   `invoice_mode` NEW (`vendor_invoice_number`, `vendor_invoice_date`, `amount`,
   `terms_of_payment_days`, optional `invoice_document`) or EXISTING
   (`vendor_invoice_reference_id`, unpaid, same PO). `GoodsReceiptService::post()` now
   requires the invoice argument (no internal "receipt without invoice" path).
   `POST /vendor-invoice-references` stays removed (405). Dependency check (frontend API
   clients, services, console commands, jobs/listeners, seeders, tests, docs): the only
   callers are the PO popup, the controller, `SupplyChainSeeder`,
   `FunctionalTestInventorySeeder`, `Phase4ConcurrencySmokeTestCommand` (updated to send a
   per-worker invoice) and the tests (updated); no jobs/listeners/integrations use either
   contract; the repository has no external API client documentation (user guide + feature
   inventory updated).
8. **MongoDB migrations** `2026_09_08_000002/000003/100001` are in the source tree unchanged
   (`git diff origin/main -- <paths>` empty). Tests / seed checks set them aside only for the
   duration of a run (wrapper with an always-restore trap; nothing staged from it). History of
   3df6356 / 3de74ac left as is (squash merge expected).

**Future improvement candidates:** holiday / business calendar for due dates; formal
PO ↔ Goods Receipt ↔ Invoice three-way financial matching; partial payments.

**Verification of the final decisions:** new tests — paid invoice reuse rejected via API (no GR,
no stock, PO status unchanged) and a new invoice accepted afterwards; payment 10,000,000 vs
9,000,000 / 11,000,000 / future date / second payment; invoice without PDF saved and reusable
while unpaid. Receipt history order is now deterministic (`gr_number` tiebreak for receipts
posted in the same second). Targeted regression 107 passed; full backend regression
**959 passed, 0 failed**; `concurrency:smoke-test-phase4` ALL RACES SAFE (its cleanup now
removes the invoice rows it creates); seeders twice on a fresh DB — identical; frontend build
PASS, lint 0 errors (baseline warnings). Browser: unpaid previous invoice offered for reuse →
after payment not offered (note shown), direct API reuse 422, new invoice without PDF posted.

## CHECKPOINT (2026-10-01) — merged, no work in progress

- **Merged:** PR #11 squash-merged into `main` as `4f50cd1`
  ("feat: sidebar UX, upload reliability, goods receipt vendor invoices and payment (#11)").
  Earlier this session: PR #9 (`000c8ee`, RFQ document optional / used spareparts / WO product
  search / reservation retirement) and PR #10 (`586de40`, billing proration test clock).
- **State of `main`:** all phases of this improvement (sidebar, upload foundation, GR → vendor
  invoice, invoice list + working-day due dates + derived status, payment with proof, paid
  invoices not reusable, seeders) are complete. Last validation on the merged content:
  full non-Mongo backend regression 959 passed / 0 failed; frontend build PASS, lint 0 errors;
  `concurrency:smoke-test-phase4` all races safe; seeders twice on a fresh DB identical.
  Mongo-dependent tests NOT RUN (no MongoDB in this environment). The three Mongo migrations
  (`2026_09_08_000002/000003/100001`) are present on `main`, unchanged.
- **Open items / future candidates (not started; need owner instruction):** holiday /
  business calendar for due dates; PO ↔ Goods Receipt ↔ Invoice three-way matching; partial
  vendor payments.
- **Resuming notes:** branch `claude/magical-volta-tv4xwl` is restarted from `main` for any
  follow-up (new PR). Local test runs must set the Mongo-only migrations/tests aside only for
  the run and restore them (never stage those paths); stage explicit file paths only.
