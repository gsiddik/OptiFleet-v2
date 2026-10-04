# Comprehensive Improvement from main — Status

Branch `claude/magical-volta-tv4xwl`, baseline `main` @ 55c32df.

| Phase | Scope | Status | Commit |
|---|---|---|---|
| 1–5 | Purchase Order Return to Vendor (Return Order, Refund / Redelivery, history, print, state model) | DONE | 4465d97 |
| 6 | Work Order tabs: order, orphaned External Services / Documents, QC / Road Test at QC_PENDING | DONE | afe1657 |
| 7 + 10 | Orphaned Warranty / Eligibility / Claims and the old Tire Operations page | DONE | e44e38b |
| 13 | Maintenance Package `componentGroup` relationship (root cause) | DONE | 0d8b5ad |
| 12 | Vehicle Documents: Vehicle Tax, expiry, extension, upload gating | DONE | f3a1cd5 |
| 11 + 14 | Global image container 480 × 320; Product / Tire Product Details layout | DONE | e4e3ca3 |
| 15 + 8 + 9 | Retread Open Cycle → Receive → Tire Inspection; Retread History; serial detail cleanup | DONE | a3327f0 |
| 16 | Scrap tab: Recently Scrapped selection → Sell Sparepart (row / bulk), serial preserved | DONE | 77b4a1b |
| 17 | Demo seeder alignment: PO returns, retread states, scrapped tires, vehicle documents | DONE | see git log |

## Phases 1–5 — Purchase Order Return to Vendor

**State model** (`purchase_returns.status`, one open Return Order per PO, 1:N history):

| Option | Flow |
|---|---|
| Refund Request | REFUND_REQUESTED → REFUND_ACCEPTED (Accepted by Vendor) |
| | REFUND_REQUESTED → REDELIVERY_PENDING (Rejected by Vendor; event REFUND_REJECTED kept) → REDELIVERY_RECEIVED |
| Redelivery Request | REDELIVERY_REQUESTED → REDELIVERY_READY (Return Order printed) → REDELIVERY_RECEIVED |

Every transition is one transaction with row locks; `purchase_return_events` keeps the history
(a rejected refund stays visible as "Refund rejected → Redelivery").

**Quantities** (per PO line, backend-computed, never trusted from the UI):
- returnable = received − returned; Qty Returned must be > 0 and ≤ returnable.
- remaining receivable = ordered − (received − returned) − refunded (refund requested / accepted).
  A redelivery return re-opens its quantity for Goods Receipt; a rejected refund does too.
- Goods Receipt is refused (API and UI) while a redelivery is awaited (REDELIVERY_REQUESTED /
  READY / PENDING) and re-opens after "Receive Redelivery"; the redelivered goods are received
  with a normal Goods Receipt. Post Goods Receipt / Qty are hidden when nothing remains.

**Numbering / print**: Return Order # from the numbering service (`purchase_return`,
`RO/{YYYY}/{SEQ:6}`, platform default in ConfigurationDefaultsSeeder); Print Return Order uses the
document template architecture (`purchase_return` template, PDF preview); the first print moves a
redelivery request to REDELIVERY_READY.

**Permissions**: `purchase_return.create` (granted to roles with `goods_receipt.post`),
`purchase_return.decide` (accept / reject; roles with `purchase_order.approve`),
`purchase_return.receive_redelivery` (roles with `goods_receipt.post`); print / history with
`purchase_order.view`; PO data scope (delivery warehouse) and tenant enforced on every endpoint.

**Engineering decisions (please confirm)**
1. Returned goods leave stock at Return Order creation: `RETURN_TO_VENDOR` stock movement from
   the PO's delivery warehouse at the average cost (goods physically go back to the vendor).
2. Refunded Amount = the PO line's own pricing formula for the returned quantity (qty × unit price,
   less line discount, plus line tax; 4-decimal HALF_UP like the PO line total). The PO-level
   freight is not part of a line refund.
3. Print Return Order is available for every Return Order; only a Redelivery Request requires the
   print before "Receive Redelivery". A refund rejected by the vendor can be received at once.
4. While a refund is awaiting the vendor's decision, Goods Receipt stays available for the
   remaining quantity (the refunded quantity is no longer expected).

## Phase 6 — Work Order Detail tabs

- Order: … Planned Parts → Tire Operations → Issuance & Return … (Tire Operations only when the
  Work Order has one; Issuance & Return keeps its status rule).
- ORPHANED (hidden, component / API / data kept): External Services, Documents. Documents was a
  read-only viewer; External Workshop documents stay reachable in the External Work Order flow.
- QC and Road Test tabs only while the Work Order is QC_PENDING. A tab that is not offered never
  renders. Backend: QC start was already QC_PENDING-only; Road Test is now refused outside
  QC_PENDING too (and for External Work Orders by execution mode — the old check compared the
  status with 'EXTERNAL', which never matched). Existing seed flows record the Road Test at
  QC_PENDING, so they are unaffected.
- Note: QC / Road Test history is no longer visible on the Work Order once it leaves QC_PENDING
  (requirement); it remains in QC Inspections and the data is kept.

## Phases 7 + 10 — Orphaned features

| Feature | Active UI | Kept |
|---|---|---|
| Warranty, Eligibility, Claims | removed from navigation and from the UI router; dashboard "Active Warranty Claims" tile hidden | pages/tenant/warranty, Warranty APIs (still respond), models, tables, history, notification event `warranty_claim.submitted`, Warranty analytics |
| Old Tire Operations page (tabbed Installation / Rotation / Inspection) | `/app/tire-operations/legacy` no longer routed | TireOperationsPage source, tire install / rotate / inspect APIs (used by other flows) |

Dependency audit: no other page links into the Warranty pages. Vehicle Documents keeps its
"Warranty" document type (a document category, not the Warranty module); the dashboard API still
returns `warranty_claims_active`; Warranty analytics (Analytics menu) is unchanged. The new Tire
Operations page (`/app/tire-operations`) is unaffected.

## Phase 13 — Maintenance Package `componentGroup` error

- Root cause: `maintenance_package_items.component_group_id` is a real nullable FK to
  `component_groups` (2024_03_01_000003), the package controller eager-loads
  `items.componentGroup` (show / activate / update items) and the frontend reads
  `item.component_group`, but `MaintenancePackageItem` never defined the relation. Eager loading
  only resolves it when a package has items, so new empty packages worked and every seeded
  package (5 packages, 9 items) failed to open.
- Fix: `MaintenancePackageItem::componentGroup()` (BelongsTo ComponentGroup) — no caller change,
  no schema change. Seeder already used the FK; nothing to change there.
- Test: package detail with items (with / without a component group) and activate; all seeded
  packages load with the relation.

## Phase 12 — Vehicle Documents

- Document Type gains **Vehicle Tax** (same document architecture: storage, preview, download).
- Per upload: "Have an Expiry Date?" → Expiry Date (mandatory when checked); "Need to be
  extended?" → Extension Deadline (mandatory when checked). Upload allowed when nothing is checked
  (case A) or every shown date is filled (case B).
- Backend: `required_if` validation, unchecked boxes store no date, DB CHECKs
  (`has_expiry = (expiry_date IS NOT NULL)`, same for the extension). Existing documents with an
  expiry date were backfilled as `has_expiry = true`; a legacy client sending only `expiry_date`
  still works.

## Phases 11 + 14 — Image container standard, Product Details layout

- `components/ImageContainer.tsx`: `ImageContainer` (width 100%, max-width 480px, aspect-ratio
  3:2 → 480 × 320 on desktop, proportional below 480px; image `object-fit: contain`, never
  distorted) and `DetailsWithImage` (details | image in one top-aligned row on desktop, image
  wraps below the text on narrow screens, no horizontal overflow).
- Applied to the main detail images: Product / Tire Product Details (shared
  ProductDetailsSection — image top-aligned with the SKU / Type / Category / UOM row, Upload /
  Replace / Remove under it, **Edit Product beside the "Details" heading**) and the Vehicle photo
  (inline with the specifications).
- Not changed (not detail image containers): evidence thumbnails / galleries, logos, document
  previews in viewers.

## Phases 15 + 8 + 9 — Retread processing, Retread History, serial detail cleanup

**Used Tire Management → Retread** ("Tires in Retread / Repair Cycle" — repair is a kind of
retread, both use the same flow):

| Step | Cycle status (existing) | State shown | Where the tire is |
|---|---|---|---|
| waiting (tire RETREAD / REPAIR) | — | Waiting → **Open Cycle** | cycle list |
| Open Cycle saved (Retread Form) | SENT | IN_PROCESS → **Receive** | cycle list |
| Receive (opens the Tire Inspection) | RECEIVED | RECEIVED → **Inspect** | cycle list |
| Tire Inspection submitted | FINAL_INSPECTED | REINSPECTION → **Review Inspection** | cycle list |
| Tire Inspection approved | APPROVED, `final_status` = disposition | COMPLETED | "Recent Retread / Repair Cycles"; tire status = disposition |

- Retread Form: Processed At (vendor — the existing eligible types EXTERNAL_WORKSHOP /
  TIRE_SUPPLIER, active; no new classification), Estimated Price (mandatory, > 0, exact decimal,
  stored in the cycle's `cost`), Photo (mandatory, 1–3, JPG / PNG ≤ 3 MB, private disk —
  `tire_cycle_photos`), Notes (optional).
- The cycle completes only after Receive **and** the approved Tire Inspection; a cancelled
  re-inspection returns the cycle to RECEIVED. The legacy final-inspect endpoint refuses cycles
  opened here. Retread count for the decision engine counts these completed cycles (not scrapped).
- **Retread History** (shared `RetreadHistory`, `/tires/:id/cycle-history`): vendor, estimated
  price, photos, notes, open / receive dates, inspection result, final status — shown only when
  the tire has cycles.
- **Serial detail** (Used Stocks and Recent Removals both open `/app/tires/:id`): Structured
  Scoring, Sell and Scrap sections removed from the UI; the retread / repair governance panels are
  replaced by the read-only Retread History (actions moved to Used Tire Management). Backend
  scoring / sell / scrap / governance APIs are unchanged.

## Phase 16 — Scrap tab → Sell Sparepart

**Used Tire Management → Scrap**: the "Used tires that can be scrapped" list is removed (a tire is
scrapped through its inspection's SCRAP outcome). The tab shows only **Recently Scrapped**
(`GET /tires-scrapped`, `tire.view`, tenant + data scope; SCRAPPED tires with any open / approved
sale): row checkbox, header Select All (current page only), bulk **Sell** above the table
(disabled until ≥ 1 selected) and row **Sell**. A tire already in a DRAFT / PENDING_APPROVAL /
APPROVED sale cannot be selected. Both actions open Sell Sparepart with `?tire_ids=`.

**Sell Sparepart → "Sell scrapped tires"** lists the selected tires (serial, product, status, open
sale) and posts `POST /sparepart-sales/scrapped-tires` (`sparepart_sale.create`): buyer (External
name or Partner), unit price per tire (exact decimal), notes. One DRAFT sale per tire,
`source_type` SCRAPPED_TIRE, quantity 1, sale type SCRAP_MATERIAL only, carrying
`tire_serial_number`, `tire_status`, `tire_condition` (inspection reasons / removal condition).

- Backend guards: tires must belong to the tenant and the user's data scope (403), be SCRAPPED
  (422), and have no active sale (partial unique index, 422). Rows are locked during creation.
- The existing Submit → Approve / Reject (maker ≠ approver) flow applies; **Approve** marks the tire
  SOLD (no stock movement — a scrapped tire is not stock); Reject frees it for a new sale.
- Migration `2026_10_07_000004` (additive): `source_type`, tire columns, `work_order_part_return_id`
  / `warehouse_id` nullable for tire sales, CHECK per source type. Existing used-sparepart sales
  default to USED_SPAREPART — unchanged behaviour.
- Tests: `ScrappedTireSaleTest` (4) + `SparePartSaleTest` (8) PASS; e2e 16/16 PASS (0 / 1 / many
  selection, Select All on/off, bulk + row Sell, serial in form and sale list, sold tire not
  re-selectable, IN_STOCK tire refused 422, mobile 390 px no overflow).

## Phase 17 — Demo seeder alignment

`DemoDatasetSeeder` (ALPHA), every record through the domain services, idempotent (natural keys:
PR notes, deterministic demo serials, document numbers):

| Area | Demo data |
|---|---|
| PO Return to Vendor | "Demo restock: batteries" stays Partially Received with no return; four new partially received POs with RO/2026/… returns: Refund Requested, Refund Accepted (refund amount recorded), Refund Rejected → Redelivery Pending, Redelivery Requested (not yet printed) |
| Retread | Retread-program truck **H 3203 ALP** (6 tires replaced by new casings): 1 RETREAD tire waiting for Open Cycle, 1 in process (SENT), 1 received pending inspection, 1 completed (inspection approved → REUSE); 2 photos per cycle (`DemoPhoto`, plain-PHP PNG) |
| Scrap | 3 SCRAPPED tires (bus cord exposure + 2 truck tires scrapped through inspection) for row / bulk Sell |
| Vehicle documents | STNK (expiry), Vehicle Tax (expiry + extension deadline), insurance (expiry), KIR certificate (expired, extension pending), BPKB (no expiry) |

- `procurement()` chain body extracted to `purchaseChain()` (no behaviour change); the used-tire
  inspection closure extracted to `inspectUsedTire()`.
- Validation: `migrate:fresh --seed` PASS, re-seed (`db:seed`) leaves all counts unchanged;
  `DemoDatasetSeederTest` 3/3 PASS (new assertions for returns, cycle states, photos, scrapped
  tires, documents and re-run idempotency).
