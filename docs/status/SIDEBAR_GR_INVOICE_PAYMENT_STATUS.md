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
