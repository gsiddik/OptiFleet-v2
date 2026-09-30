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
