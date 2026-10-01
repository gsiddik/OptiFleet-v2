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

## Phase 5 — Inventory Reservation retired (DONE)

Replaced by Part Requests (REQUESTED → APPROVED → ISSUED). Approval holds no stock; availability
(on hand − reserved) is checked at Issue, in the issue transaction.

- Migration `2026_10_01_000009_retire_inventory_reservations` (idempotent, non-destructive):
  every DRAFT / RESERVED / PARTIALLY_RESERVED reservation is released once through
  `InventoryService::releaseReservation` (RELEASE_RESERVATION ledger entry, quantity back to
  Available), item `reserved_quantity` → 0 (requested quantity kept), linked legacy planned-part
  reserved quantity released (status back to PLANNED when nothing was issued), reservation marked
  RELEASED with reason "Inventory Reservation retired — replaced by Part Requests". Then the
  `inventory.reserve` permission and its role grants are deleted. `down()` re-creates the
  permission row (no grants); released stock is not re-held.
- Removed: `/stock-reservations` routes, `StockReservationController`, `StockReservationService`,
  dead `WorkOrderPartService::reserve`, `inventory.reserve` from Permission / FunctionalTestUser /
  DemoData seeders; frontend Inventory → Reservation page, route, menu entry, breadcrumb label,
  `StockReservation*` TS types.
- Kept (shared / history): `stock_reservations` + `stock_reservation_items` tables and models,
  `InventoryService::reserve/releaseReservation` (generic primitives, used by the migration and
  the Phase 4 concurrency smoke command), `warehouse_stocks.quantity_reserved`, dashboard
  "reserved stock" metric, RESERVATION / RELEASE_RESERVATION movement types and labels, legacy
  planned-part own-reservation consumption in `InventoryService::issue`. Workspace (bay)
  reservations untouched.
- Tests: `InventoryReservationRetirementTest` (release once + history kept; endpoints 404 +
  permission gone; Part Request approve holds nothing, issue checks stock); related regression
  133 passed. Seeders twice on a fresh DB: identical counts, `inventory.reserve` absent.
- Browser: Reservation menu gone, `/app/stock-reservations` falls back to the dashboard, API 404.

## CHECKPOINT (2026-10-01) — paused by owner before Phase 6

Branch `claude/magical-volta-tv4xwl` (baseline main @ 773c2cd). Pushed commits:

| Phase | Commit | Message |
|---|---|---|
| 2 RFQ document optional | 6d9df1e | quotation document optional |
| 3 WO product dropdown | 8c8cec6 | searchable product dropdown |
| 4 Used Spareparts tab | 2b4bb3b | used spareparts availability + complete repair |
| 5 Reservation retired | 23a658b | refactor: retire inventory reservation workflow in favor of part requests |

Validation done so far (executed in-session): targeted backend tests per phase (R2 31, R4 84,
R5 related regression 133 passed), Pint on touched files, frontend `npm run build` PASS and
`npm run lint` (0 errors, warnings identical to baseline), seeders twice on a fresh DB, browser
E2E for Phases 3–5. Mongo-dependent tests: NOT RUN (no MongoDB in this environment).

## Phase 6 — Final regression (DONE)

- Full backend suite (`php artisan test`, serial, PostgreSQL, Mongo-dependent migrations/tests
  temporarily set aside and restored afterwards): **932 passed, 2 failed** (4847 assertions).
  - `VendorQuotationListTest` (query count): timing-sensitive, not a code regression. Sanctum's
    `last_used_at` token touch is only written when the clock second changes, so a request that
    crosses a second boundary issues one extra UPDATE. Reproduced deterministically with a forced
    1 s delay (13 vs 12); the test now excludes that bookkeeping query and still detects N+1
    (passes with and without the delay).
  - `BillingAndInvoiceTest::proration_applied_when_module_added_mid_period`: date-dependent and
    not related to this work (billing untouched). The test assumes a 30-day billing period
    ("15 of 30 days"); for a contract starting 2026-10-01 the period is 31 days, so 16/31 of
    300 000 = 154 838.71 ≠ 150 000.00. The test and all proration code (Billing, Contract,
    Invoice, Pricing) are byte-identical to main; a run on main was not executed (an isolated
    main checkout was not possible here). Not fixed here (out of
    scope); proposed fix: derive the expected amount from the actual period length.
- Frontend at final HEAD: `npm run build` PASS; `npm run lint` 0 errors, warnings identical to
  baseline. No typecheck/test scripts exist.
- Mongo-dependent tests (Analytics, Intelligence): NOT RUN — no MongoDB in this environment.
