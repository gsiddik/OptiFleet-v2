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
