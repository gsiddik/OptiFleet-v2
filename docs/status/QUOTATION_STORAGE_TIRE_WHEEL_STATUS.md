# Quotation Create PO Guard, Private File Storage, Tire Creation, Wheel Configuration Prototype — Status

Branch `claude/magical-volta-tv4xwl`, baseline `main` @ 4a858d0.

## Phase A1 — Quotation → Create PO (DONE)

Audit:
- Relation: `purchase_orders.vendor_quotation_id` (FK, **partial unique index**
  `purchase_orders_vendor_quotation_unique`) — one PO per quotation. Source of truth for "already
  converted".
- Quotation statuses (DB check constraint): `SUBMITTED`, `SELECTED`, `REJECTED`. `CLOSED` is an
  **RFQ** status (set when a vendor is selected, or by a manual close without selection) —
  a quotation itself can never be `CLOSED`.
- Backend already refused non-selected quotations and a second PO (row lock + unique index).
- Gaps: RFQ Detail showed **Create PO for every SELECTED quotation, even after a PO existed**;
  the Create PO page opened by direct URL showed the form; and `selectVendor` did not check the
  RFQ status, so a quotation of a manually CLOSED / CANCELLED RFQ could still be selected and
  converted.

Implemented:
- `VendorQuotation::purchaseOrder()` (hasOne via `vendor_quotation_id`) and
  `canCreatePurchaseOrder()` = status `SELECTED` and no PO — one rule, used by the API payloads
  (`can_create_purchase_order`, `purchase_order {id, po_number, status}`) on RFQ compare,
  quotation detail and quotation list (eager-loaded, no N+1).
- `PurchaseOrderService::createFromQuotation` (unchanged guard, clearer message
  "This quotation is {STATUS}: only a selected quotation …") → 422; duplicate → 422 "already
  converted" (row lock + unique index backstop).
- `RfqService::selectVendor`: RFQ must be `ISSUED` and the quotation `SUBMITTED` (row-locked) → 422.
- UI: RFQ Detail shows Create PO only when `can_create_purchase_order`, otherwise a link to the PO;
  Select only while the RFQ is ISSUED. Vendor Quotations list gets a "Purchase Order" column
  (PO link / Create PO / —). The Create PO page opened directly for an ineligible quotation shows
  the existing PO (or the reason) instead of the form.

Tests: `PurchaseOrderFromQuotationTest` +2 (eligible → offered in compare/show/list and accepted;
converted → hidden everywhere with PO number, API 422, still 1 PO; RFQ closed without selection →
not offered, API 422, select 422; REJECTED → not offered, 422) — procurement suites 41 passed.
Browser (Alpha): eligible quotation → Create PO on RFQ and list → PO created; afterwards no
Create PO anywhere, PO link shown, direct URL shows "already created … PO/2026/000002", direct API
rejected; manually closed RFQ → no Select / Create PO, select API rejected.
