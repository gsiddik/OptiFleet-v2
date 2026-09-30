# Numeric Standards, Work Order Parts, Warehouse Stock & Procurement — Improvement Status

Status: **IN PROGRESS**. Branch `claude/magical-volta-tv4xwl`, baseline `main` @ `93d67df`.

## Impact analysis (baseline audit)

| Area | Existing behavior | Required behavior | API / DB impact |
|---|---|---|---|
| Numeric inputs (FE) | 144 native `type="number"` inputs in 39 files; spinner visible | Text-style numeric input, no stepper, validation kept | FE only: shared `NumericInput` component |
| Quantity storage | All quantity columns `decimal(16,4)` (a few `12,2`/`10,2`); `formatQty` shows up to 4 decimals because Liter/Kg consumables use fractions | Discrete item counts shown and accepted as integers | No column conversion (history preserved); integer rule enforced in domain validation per product |
| Money | Stored `decimal(16,4)` / `(14,2)`; exact arithmetic via brick/math (`Money`, BigDecimal in RFQ/PO services); FE prints raw strings | Always displayed with 2 decimals; arithmetic stays exact | FE `formatMoney`; print templates receive 2-dp strings; no schema change |
| Tenant Product Categories | Tenant menu + read-only tenant page; `GET /app/product-categories` feeds product forms | Menu hidden for tenants; master data untouched; platform CRUD intact | Remove tenant nav entry + tenant route; keep read API (forms depend on it) |
| Warehouse Stock | No item-type tabs; `/app/inventory` filters warehouse/product/search | Tabs Parts & Supplies (SPARE_PART, CONSUMABLE, RIM, TIRE) / Tools & Equipment (TOOL, EQUIPMENT) by `products.product_type` | New `item_group` filter on `/app/inventory` (server-side, paginated) |
| WO Issued Parts | Status badge right of name; `total_cost` = issued qty × issue cost (snapshot, used by analytics `parts_cost`) | Status left of name; Unit/Total Cost 2 dp; Total Cost = consumed qty × unit cost | Backend-computed consumed cost exposed on the planned-part API |
| Removed Components | Product from full catalog; optional user-picked `replaced_by_planned_part_id` | Product only from consumed lines of the same WO; replacement derived by backend | Backend validation + derivation; FE dropdown removed; historical links kept |
| Used Sparepart evidence | Free-text evidence URL string | Upload button, JPG/PNG only, max 3 MB, private storage | New evidence upload endpoints + table (same pattern as removed-component evidence) |
| New RFQ | Modal on RFQ list | Dedicated page: warehouse, product search, category + vehicle-model filters, multi-select with integer qty, saves DRAFT | Product lookup filter for vehicle model; RFQ create validation hardened |
| RFQ vendors | Any partner invitable; no print; no removal (pivot `rfq_vendors`) | Only SUPPLIER / SPARE_PART_SUPPLIER / TIRE_SUPPLIER; per-vendor print; safe removal | Backend type check; new `rfq` document template; removal endpoint |
| Quotation | No attachment; Submitted vendors still selectable | Attachment (PDF/DOC/DOCX) required; secure view/download; submitted vendors excluded | New attachment columns/endpoints; backend duplicate prevention |
| Vendor Quotations list | RFQ column shows id | Shows RFQ number | Eager-load `rfq:id,rfq_number` |
| Create PO | Vendor + total only; `order_date` never set | Items/qty/prices from quotation; Order Date datepicker persisted | `order_date` required on create-from-quotation |
| PO document | Expected date manual/nullable | Expected = Order Date + quotation Lead Days (calendar days, matching analytics lead-time definition) | Computed in `PurchaseOrderService`; template shows it |

Permissions: existing permissions are reused (`rfq.manage`, `quotation.manage`, `quotation.view`, `purchase_order.create`, `used_part.inspect`); no role-name checks.

Seeders: demo/functional seeders touching RFQ, quotations, POs, planned parts and removed components are re-verified against the new rules; production baseline seeders gain only the `rfq` document template default.

## Decisions

Owner decisions (2026-09-30):

1. **Discrete quantity rule** — every product quantity is a whole number, except products whose
   UOM is a measured unit (Type of Measure Capacity / Weight / Length, e.g. Liter, Kg). Those
   keep decimals. No stored quantity is converted or rounded.
2. **Work Order Total Cost** — the stored `total_cost` (issue-time cost snapshot, summed by
   analytics) is kept unchanged. The backend computes the consumed Total Cost
   (consumed qty × average issued unit cost) and every Work Order screen/API/document uses it.
3. **Removed Components** — product must be a consumed part of the same Work Order, and the total
   removed quantity per product is capped at that product's consumed quantity.
4. **Invited vendors (K3)** — the invitation list is not edited. A vendor that already recorded a
   quotation is excluded from the Record Quotation vendor dropdown, and the backend rejects a
   second quotation from the same vendor for the same RFQ.

Decisions taken from existing evidence (no owner input needed):

- **Lead days = calendar days**: analytics already measures vendor lead time as calendar days
  (`received_at − order_date`), so Expected Receipt Date = Order Date + Lead Days (calendar).
- **Quotation attachment max size = 10 MB**: the platform's existing document-upload convention
  (vehicle documents, product documents). Flagged for owner review; no procurement-specific
  limit existed.

## Phase 1 — numeric input, quantity & money standards (DONE)

- Backend `QuantityPolicy` (ProductMaster/Support): counted products reject fractional quantities
  (422, field-level message); measured UOMs (Capacity/Weight/Length, baseline LTR/KG) allow them.
  Enforced in every `InventoryService` movement and in Part Request request/approve, New-part
  return, Returned Parts Processing, Removed Components, Used Sparepart inspection, Purchase
  Request, RFQ, Vendor Quotation, Purchase Order and Goods Receipt lines. Stored data untouched.
- `uoms` payload exposes `allows_fractional_quantity`; baseline seeder tags LTR = Capacity,
  KG = Weight.
- Backend `DisplayFormat` (Shared/Support): money = 2 decimals half-up grouped; quantity without
  trailing zeros. Used by printed documents (PO, Maintenance Memo, Workshop Invoice contexts).
- Frontend: shared `NumericInput` (text input, no spinner, rejects non-numeric keystrokes) replaces
  all 144 native number inputs; CSS fallback hides spinners on any native number input;
  `formatMoney` (string arithmetic, half-up) and `formatQty` applied to money/quantity displays.
- API contracts unchanged (decimal strings kept for backward compatibility).

## Phase 2 — tenant Product Categories menu & Warehouse Stock tabs (DONE)

- Tenant menu entry, tenant route and the read-only tenant page for Product Categories removed.
  `GET /app/product-categories` stays (product forms depend on it); tenants have no write route;
  platform CRUD unchanged.
- `GET /app/inventory?item_group=PARTS_SUPPLIES|TOOLS_EQUIPMENT` classifies by
  `products.product_type` (SPARE_PART, CONSUMABLE, RIM, TIRE / TOOL, EQUIPMENT); invalid value
  422. Warehouse Stock page: two tabs, search and reorder filters inside the tab, pagination.
- Legacy `OTHER` products (no longer creatable) appear in neither tab.

## Phase 3 — Work Order Issuance & Return, Removed Components (DONE)

- Issued Parts: status badge left of the product name; Unit Cost and Total Cost shown with 2
  decimals. `WorkOrderPlannedPart` exposes `average_unit_cost` (issue cost ÷ issued qty) and
  `consumed_total_cost` = consumed qty × unit cost (exact decimal, 2 dp, half-up). Returned and
  outstanding quantity never counts. Stored `total_cost` (analytics snapshot) unchanged.
- Removed Components: product must be CONSUMED on the same Work Order; total removed per product
  capped at its consumed quantity (Work Order row locked while checking). The "Replaces which new
  part" input is gone: `replaced_by_planned_part_id` is derived from the consumed line of the same
  product (client value ignored). Historical links untouched.
- Frontend: removed-product dropdown lists only consumed products with consumed / removable
  quantities; quantity inputs use whole-number mode unless the product's UOM is measured.
- Tests updated to the new rule (fixtures now consume the new part before recording the old one).

## Phase 4 — Used Sparepart Processing evidence photo upload (DONE)

- Migration `2026_10_01_000006` (additive): `used_part_inspection_evidence` (tenant, return FK,
  private disk/path, original name, mime, size, uploader).
- `UsedPartEvidenceService`: JPG/PNG only — MIME checked from file content, extension must agree —
  max 3 MB; stored on the private `local` disk under a server-generated UUID name (client name is
  display metadata only, basename-sanitised). Add/remove only while PENDING_INSPECTION.
- Endpoints: `GET|POST /used-part-returns/{id}/evidence`, `GET|DELETE …/evidence/{evidence}`
  (view: `used_part.view`; upload/delete: `used_part.inspect`); tenant + workshop scope; storage
  path never serialised. Payload relation `evidence_photos` (named to avoid colliding with the
  legacy `inspection_evidence` URL column, which is kept read-only for history).
- Frontend: Evidence Photo "Upload" button opens the OS file picker (accept .jpg/.jpeg/.png),
  client-side type/size check with clear messages, authorized previews, remove while pending.
  The free-text evidence URL field is gone.

## Phase 5 — New RFQ page (DONE)

- "+ New RFQ" navigates to `/app/rfqs/new` (permission `rfq.manage`); the modal is removed.
- Page: destination warehouse (scope-filtered list), server-side paginated product table
  (checkbox, code, name, Product Category, vehicle compatibility), name search, Product Category
  filter (includes subcategories), Vehicle Brand → Model filter, per-line quantity (whole numbers
  unless the UOM is measured), selected-products summary kept across pages/filters; "Save as
  Draft" creates a DRAFT RFQ and opens it.
- Backend: `GET /app/products` gains `category_id` (category + direct subcategories) and
  `vehicle_model_id` (compatibility rules for the model, plus brand-wide "any model" rules of its
  brand). `RfqService::create` now rejects inactive / other-tenant products, duplicated products,
  non-positive and fractional counted quantities (field-level 422); warehouse must be the tenant's
  and inside the user's data scope (existing check).

## Phase 6 — RFQ Invited Vendors & vendor-specific print (DONE)

- `Partner::RFQ_VENDOR_TYPES` = SUPPLIER, SPARE_PART_SUPPLIER, TIRE_SUPPLIER (canonical enum
  values). `RfqService::inviteVendors` rejects other types, inactive partners, other tenants'
  partners (previously accepted) and CLOSED/CANCELLED RFQs. The invite dropdown lists only
  eligible, not-yet-invited vendors.
- Print per invited vendor: `GET /app/rfqs/{rfq}/vendors/{partner}/print` (`rfq.view`, data
  scope, vendor must be invited) → PDF. New `rfq` document type in TemplateVariableRegistry +
  `DocumentTemplateContextBuilder::forRfqVendor`; default body `RfqDocumentTemplate` (RFQ No,
  RFQ date, addressed vendor, destination warehouse, item code/name/qty/UOM, request for unit
  price and lead time after PO, "Issued by" signature block with the printing user). Seeded as
  the platform default; the print falls back to the same body until a database is re-seeded.
- K3 (remove invited vendor): replaced by owner decision 4 (no invitation removal; see Phase 7).

## Phase 7 — Quotation document & Quotation Comparison (DONE)

- Migration `2026_10_01_000007` (additive, nullable): `vendor_quotations.attachment_*` columns.
- Record Quotation (`POST /rfqs/{rfq}/quotations`, multipart) now requires the vendor's quotation
  document: PDF / DOC / DOCX, type decided from file content (DOCX must be a real Word package),
  max 10 MB (platform document convention — owner decision still open), private disk, UUID name.
  Stored only after all business checks pass; removed again if saving fails.
- Business rules (RfqService): RFQ must be ISSUED; vendor must be invited; ONE quotation per
  vendor per RFQ — a vendor already in Quotation Comparison is rejected (previously silently
  overwrote the first quotation); each line must be a distinct item of the RFQ.
- `GET /quotations/{id}/attachment[?download=1]` (`quotation.view`, tenant + warehouse scope,
  `nosniff`) views inline or downloads; storage path never serialised (`has_attachment` flag).
- Frontend: Record Quotation form with vendor dropdown excluding vendors already in the
  comparison, unit price per RFQ line, lead time, quotation no., file picker (selected name,
  replace/remove, type/size validation); comparison rows show the document with View / Download.
- Demo/functional seeders attach a generated placeholder PDF (`DemoQuotationDocument`); seeding
  twice gives identical counts.

## Phase 8 — Vendor Quotations list (DONE)

- RFQ column shows the RFQ Number (e.g. `RFQ/2026/000123`), never the id; `rfq:id,rfq_number,…`
  is eager-loaded (query count constant regardless of rows — covered by a test).
- The list is now also limited to RFQs inside the user's warehouse data scope (detail endpoints
  already enforced it) and is paginated.

## Phase 9 — Create Purchase Order, Order Date, Expected Receipt Date (DONE)

- `POST /quotations/{id}/purchase-order` requires `order_date` (YYYY-MM-DD, real calendar date);
  a client `expected_delivery_date` is no longer accepted there.
- `PurchaseOrderService::createFromQuotation`: lines, quantities and vendor unit prices always
  copied from the selected quotation; persists `order_date`; `expected_delivery_date` =
  Order Date + quotation `lead_time_days` (calendar days; null when the vendor gave no lead
  time) via `PurchaseOrderService::expectedReceiptDate`. No schema change (columns existed).
- PO document: context already carries both dates; seeded default label now "Expected Receipt
  Date" (existing published templates are never overwritten and show the derived value).
- Frontend: Create Purchase Order shows the quotation lines (product, qty, vendor unit price,
  line total, total), vendor lead time, delivery warehouse (defaults to the RFQ's), Order Date
  datepicker, and a preview of the Expected Receipt Date; PO detail shows both dates.
- Demo/functional seeders pass an Order Date (demo PO: 2026-09-30 → expected 2026-10-07).
