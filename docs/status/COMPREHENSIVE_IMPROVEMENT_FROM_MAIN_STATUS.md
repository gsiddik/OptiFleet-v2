# Comprehensive Improvement from main — Status

Branch `claude/magical-volta-tv4xwl`, baseline `main` @ 55c32df.

| Phase | Scope | Status | Commit |
|---|---|---|---|
| 1–5 | Purchase Order Return to Vendor (Return Order, Refund / Redelivery, history, print, state model) | DONE | 4465d97 |
| 6 | Work Order tabs: order, orphaned External Services / Documents, QC / Road Test at QC_PENDING | DONE | afe1657 |
| 7 + 10 | Orphaned Warranty / Eligibility / Claims and the old Tire Operations page | DONE | e44e38b |
| 13 | Maintenance Package `componentGroup` relationship (root cause) | DONE | see git log |

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
