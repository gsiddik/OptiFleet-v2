# RFQ Document, Used Spareparts, WO Product Search, Reservation Retirement — Status

Status: **IN PROGRESS**. Branch `claude/magical-volta-tv4xwl`, baseline `main` @ `773c2cd`.

## Audit (main @ 773c2cd)

- **RFQ quotation document**: mandatory in three layers — controller validation (`required`),
  `RfqService::submitQuotation` guard, and the RFQ Detail form (required marker, disabled submit).
  DB columns `vendor_quotations.attachment_*` are already nullable (no migration needed). There is
  no quotation edit endpoint: a vendor records one quotation per RFQ (owner decision); existing
  documents are served by `GET /quotations/{id}/attachment`.
- **Used spareparts**: `work_order_part_returns` (REMOVED_COMPONENT / USED_PART sources) carry
  `disposition_status` (PENDING_RETURN → PENDING_INSPECTION → INSPECTED → PENDING_APPROVAL →
  FINALIZED / REJECTED) and `disposition` (REPAIR, REUSE, QUARANTINE, SCRAP, SELL_ELIGIBLE).
  Approved REUSE posts one RETURN movement into the product's regular `warehouse_stocks` row
  (merged with new stock). REPAIR / QUARANTINE / SCRAP / SELL_ELIGIBLE post nothing. REPAIR is a
  terminal FINALIZED state — there is no "repair completed" step today.
- **WO product picker** (Issuance & Return → Reserve): separate search input + `<select>`, server
  search on `/app/products` (debounced, 50 rows). No reusable searchable dropdown exists.
- **Inventory Reservation** (`stock_reservations`, `stock_reservation_items`): nothing creates
  reservations any more (`WorkOrderPartService::reserve` has no caller since Part Requests).
  Remaining surface: `GET /stock-reservations`, `GET /stock-reservations/{id}`,
  `POST /stock-reservations/{id}/cancel` (`inventory.reserve`), Inventory → Reservation page and
  menu, breadcrumb label, TS types, `inventory.reserve` permission (+ functional/demo role grants),
  dead `WorkOrderPartService::reserve`, `InventoryService::reserve/releaseReservation`.
  Shared and retained: `warehouse_stocks.quantity_reserved` (availability = on hand − reserved),
  `InventoryService::issue` own-reservation consumption for legacy planned-part reservations.
  Workspace reservations (workshop bays) are a different domain and untouched.

## Phase 2 — RFQ quotation document optional (DONE)

- Backend: `attachment` is `nullable`; `RfqService::submitQuotation` records without a document
  (no file stored) or stores and validates one when supplied (PDF/DOC/DOCX, content-checked,
  10 MB). No schema change. Existing documents unchanged and still served.
- Frontend: Quotation Document field optional (no required marker, submit not blocked); rows show
  "Document available: <name>" with View / Download, or "No document uploaded".

## Phase 3 — Work Order searchable Product dropdown (DONE)

- New reusable `components/SearchableSelect.tsx`: combobox button showing the selection
  ("Name — SKU"); the search input sits inside the opened list (auto-focused), server-side search
  with 250 ms debounce (`loadOptions`), keyboard (↑/↓/Enter/Esc), click-outside close; stores the
  option value (Product id), never the display text.
- Issuance & Return → Reserve uses it for Product (active products, `GET /app/products?search=`,
  50 per query); the separate "Search product" input is gone. Quantity input still follows the
  selected product's UOM. No backend/API change.
- Verified in the browser: search inside dropdown, filtering, mouse and keyboard selection, Part
  Request created with the selected Product id.

## Owner decisions (2026-10-01)

1. **Historical stock reservations**: a one-time migration releases every still-active
   reservation (RELEASE ledger entry, quantity back to Available, reservation marked released with
   reason "feature retired"); tables and history are kept; UI, routes and permission are removed.
2. **Used sparepart stock semantics**: approved REUSE keeps posting into the product's regular
   on-hand (issuable via Part Requests). The Used Spareparts tab is a traceability view: Reusable
   (already in on-hand, counted once), Quarantine and Repair-pending (physical, unavailable).
3. **Repair → Reuse**: new "Complete Repair" action on finalized REPAIR items records who/when,
   sets the condition to Good and returns the item to INSPECTED; it then goes through the
   existing propose + maker-checker approval (e.g. REUSE → restock). Until completed it is
   Repair-pending (not reusable).

## Phase 4 — Warehouse Stock › Used Spareparts (DONE)

- Separate **Used Spareparts** tab (used and new stock never mixed in one table).
- `GET /app/inventory/used-spareparts` (`inventory.view`, tenant + warehouse scope) reads the
  Used Sparepart Processing records only (`UsedSparepartAvailabilityService`) — no inventory rows
  are created, so one physical part = one representation:
  - REUSABLE = FINALIZED REUSE (incl. Repair → Reuse, flagged `repaired`); its quantity was posted
    once into the product's regular on-hand by the approval (issued via Part Requests).
  - QUARANTINE = FINALIZED QUARANTINE — visible, never available.
  - REPAIR_PENDING = FINALIZED REPAIR without `repair_completed_at` — visible, not reusable.
  - Excluded: SCRAP, SELL_ELIGIBLE, and anything not finalized.
  - Filters: category, warehouse, product, work order, vehicle, search (product/SKU, WO, plate);
    `meta.summary` = reusable / quarantine / repair-pending quantities, never summed together.
  - Columns: product, SKU, quantity, condition, disposition (REPAIR → REUSE when repaired),
    availability, processing status, warehouse, bin (product default bin), origin WO, vehicle.
- Repair → Reuse: migration `2026_10_01_000008` (additive, nullable `repair_completed_at/by`,
  `repair_notes`); `POST /used-part-returns/{id}/complete-repair` (`used_part.inspect`) on a
  finalized REPAIR → condition Good, status INSPECTED; the next disposition (REUSE etc.) goes
  through the existing propose + maker-checker approval. Never moves stock by itself.
- Used Sparepart Processing UI: "Complete Repair" (notes) on repair-pending items.
