# VMS ↔ OptiFleet Reconciliation Traceability Matrix

## Status of this document

**PARTIAL — not a complete field-by-field audit of all 29 VMS pages.** This
session ran a repository-wide read-only audit (4 parallel domain-cluster
passes covering every nav group below) against the full text of
"Analisis Menyeluruh VMS untuk Improvement OptiFleet" (VMS analysis,
Indonesian, Super Admin read-only observation of VMS Transtrack v2.11.14)
and the Consolidated Gap Analysis Report (G-01–G-43, BD-1–BD-8). That audit
produced page-level and decision-level findings for every group, which are
recorded below. It did **not** produce a field-by-field row for every one
of the ~150+ individual form fields the VMS document lists — that would be
the natural next increment if the owner wants it, and the "Not yet
field-audited" markers below say exactly where.

Per the VMS document's own §1/§13 scope limitation: it is a **read-only**
Super Admin observation. It did not test data persistence, status changes,
deletion, final approval, file import, export, print/memo output, or
access rights for any role other than Super Admin. A VMS observation is
evidence of a **screen existing**, not evidence that OptiFleet's equivalent
capability is missing, broken, or that the VMS behavior is the correct
target — each row's decision reflects that distinction.

## Decision legend

- **ADJUST** — implemented or queued: a genuine, safe, bounded gap where
  OptiFleet should add/fix something.
- **KEEP_OPTIFLEET** — OptiFleet's existing design is equal or superior;
  no VMS-driven change warranted.
- **DEFER** — requires a real product/business decision (new subsystem,
  conflicting taxonomy, unestablished policy) before any code is written.
- **BLOCKED** — explicitly out of scope per platform safety rules (tire
  scoring/disposition thresholds, invented settlement/accounting policy).
- **NOT_APPLICABLE** — VMS observation doesn't map to a comparable
  OptiFleet concept and doesn't need to.

## Matrix by navigation group

### 1. Utama (Dashboard, Scheduled Maintenance, Work Order)

| VMS page | VMS §3 observation | OptiFleet page/route | Gap ID | Decision | Reason / evidence |
|---|---|---|---|---|---|
| Dashboard | Refresh; Change Company; filters; KPIs (low stock, most-used, nearest-schedule, scheduled-vs-WO, most-costly-vehicle, maintainer performance) | `frontend/src/pages/tenant/analytics/*`, `Intelligence*` pages; `analytics.*`/`intelligence.*` permissions | — | DEFER | OptiFleet's analytics/intelligence dashboards are a materially different, ETL-backed architecture (Phase 6/7), not a single ad-hoc KPI page. Replicating VMS's exact KPI set (most-costly-vehicle, maintainer ranking) is a scope decision for the analytics roadmap, not a copy-the-widget task. |
| Scheduled Maintenances | Search/Filter/Add/Reschedule/Cancel/Delete, pagination | `MaintenanceSchedulePage` — policy-driven auto-generation, no manual create/reschedule/cancel/delete | G-01 (partial) | DEFER | Deliberate architectural choice already made in an earlier phase (schedules are generated from `MaintenancePolicy`, not manually authored). Manual CRUD on schedules would undermine that policy-driven model. G-01's actual content (Maintenance **Package**/Interval/Item admin UI) is a separate, still-open gap — see Known Blockers in `IMPROVEMENT_CONTEXT.md`. |
| Work Order | Status summary/Search/Filter/New/View/Delete; cost estimation; result | `WorkOrderListPage`/`WorkOrderDetailPage`, `work_order.*` permissions | G-02, G-03, G-04, G-05 | Mostly ADJUST, done in Phase G + this session | G-02 (cost estimation + result) and G-03 (schedule→WO conversion) closed in Phase G (see corrected mapping). G-05 (Done/Done-with-notes vs. QC-gated completion) closed in Phase G via a new Done UI action. **G-04 (print route unwired) closed this session** — see Implemented Adjustments below. |

### 2. Master Data

| VMS page | VMS §3/§4 observation | OptiFleet page/route | Gap ID | Decision | Reason / evidence |
|---|---|---|---|---|---|
| Categories | Search/Add/View/Edit/Delete | `ProductCategoriesPage` (`/app/product-categories`) | G-40 | ADJUST — done in Phase G | Full CRUD + frontend page added (previously backend-only, no page). |
| Vehicle Categories | Search/Add/View/Edit/Delete; axle count | `VehicleCategoriesPage` | — | KEEP_OPTIFLEET | Already an established full CRUD page pre-dating this reconciliation. |
| Vehicle Brand and Model | Search/Add brand+model; grouped totals; logo | `VehicleBrandsPage`/`VehicleModelsPage` | G-38 (partial) | ADJUST — done in Phase G | Brand/Model master data added. Logo upload not replicated (no evidence OptiFleet's existing image-upload pattern was extended here; not fabricated). |
| Unit (Uom) | Search/Add/detail/edit; name, initial, **description** | `UomsPage` (`/app/uoms`) | G-41 | ADJUST — page done in Phase G; **description field still open** | Standalone frontend page + update/delete added in Phase G. The `description` field itself was not added to the `uoms` table/form — small, bounded follow-up, no policy question involved. |
| Products | Search/Filter/Import/Add/generate SKU; compatibility | `ProductListPage`/`ProductDetailPage` | — (SKU auto-gen is part of G-38) | DEFER (SKU auto-gen only) | Manual SKU entry already works; VMS's "generate SKU" implies an auto-numbering scheme with no documented format — would need the same numbering-policy owner used elsewhere (`NumberingConfiguration`), not a fabricated pattern. |
| Engine Model / Engine Type | Search/Add/detail/edit; empty state in sample tenant | none — zero code trace | — | DEFER | Whole new subsystem with no established OptiFleet analog (vehicles don't currently model engine as a discrete entity). Needs a product decision on whether/how Engine Model/Type relates to `VehicleModel`. |

### 3. Worker Management

| VMS page | VMS §3 observation | OptiFleet page/route | Gap ID | Decision | Reason / evidence |
|---|---|---|---|---|---|
| Worker | Search/Filter/Add/Detail, pagination | `WorkerListPage` — backend supports filter+pagination; frontend list UI does not yet expose them | — | ADJUST (queued, not yet implemented) | Backend `WorkerController::index()` already accepts `search`/pagination params; only the frontend Filter UI + Pagination component wiring is missing — bounded, no new backend work. |
| Work Shift | Setting; 3 shifts; assign/edit worker; per-weekday view | none — zero code trace | — | DEFER | Whole new subsystem (shift scheduling, weekday assignment). No existing OptiFleet concept to extend safely without inventing a data model. |

### 4. Vehicles Management

| VMS page | VMS §3 observation | OptiFleet page/route | Gap ID | Decision | Reason / evidence |
|---|---|---|---|---|---|
| Vehicles | Search/Filter/Import/Export/New/detail; technical spec, maintenance values | `VehicleListPage`/`VehicleDetailPage` | — | KEEP_OPTIFLEET | Already a full CRUD + import/export-capable page pre-dating this reconciliation; VMS shows no field OptiFleet's `Vehicle` model lacks at the level this audit could confirm. Not yet field-audited value-by-value against VMS §4's exact column list. |

### 5. Tire Management

| VMS page | VMS §3 observation | OptiFleet page/route | Gap ID | Decision | Reason / evidence |
|---|---|---|---|---|---|
| Rim | Search/Filter/New/Detail/Edit/Delete; brand/material/dimensions/bolt spec/code | `RimsPage` (`/app/rims`), `RimController` | **G-09** | **ADJUST — done this session** | New entity: migration, model, `StoreRimRequest`/`UpdateRimRequest`, controller (full CRUD, tenant-scoped), permissions (`rim.view`/`rim.manage`), 5/5 tests passing (`RimTest.php`), frontend list+form page, route + nav entry wired. Deliberately not linked to `Tire`/`WheelConfiguration` (no source evidence establishes that relationship) and code is user-supplied (matches every other master-data entity's pattern; VMS shows no concrete auto-generation format to copy). Supersedes an earlier session's "declined" decision on Rim, which was made without direct access to this VMS evidence. |
| Tire | Search/Filter/New/Detail/Edit/Delete; vehicle class, size, load/speed indices, construction, pattern, tube type | `TireListPage`/`TireDetailPage` | G-11 | ADJUST — done in Phase G | Discrete tire spec fields added (Phase G, correctly cited as G-11). Not yet field-audited whether every VMS-listed attribute (tube type, pattern) has a 1:1 OptiFleet column — flagged for a future pass if exact parity matters. |
| Wheels Configuration | Search/Filter/New/action; axle/wheel counts, config code | `WheelConfigurationListPage` (`/app/wheel-configurations`) | — | ADJUST (queued, not yet implemented) | Create exists; **Edit/Delete endpoints are missing** despite the model supporting them, and Search/Filter UI + computed Total-Axles/Total-Wheels/Config-Code display are not wired on the frontend even though the backend can supply them. Bounded, no policy question. |
| Installation and Replacement | wheel position selection after prerequisite | `TireInstallPage`(-equivalent) | — | ADJUST (queued, not yet implemented) | Backend `assertValidWheelPosition()` already validates position against the vehicle's `WheelConfiguration`; frontend still uses a free-text input instead of a constrained dropdown sourced from that same configuration. Bounded UI fix, backend unchanged. |
| Tire Lifecycle (Life Cycle / Removed / Used Tire Processing tabs) | grouping, removed list, used-tire queue | Tire status filters + `used_part`-style flows | — | KEEP_OPTIFLEET (largely) | OptiFleet's tire lifecycle (Phases D/E/F) is a materially more governed state machine (maker-checker, scoring framework, retread/repair governance) than the VMS tab view shows. Not yet field-audited tab-by-tab for a specific missing filter. |

### 6. Inventory Management

| VMS page | VMS §3 observation | OptiFleet page/route | Gap ID | Decision | Reason / evidence |
|---|---|---|---|---|---|
| Sparepart (stock/returned/used-processing tabs, Sell) | condition, repair receipt, processing partner/photo/note | `InventoryPage`, `UsedPartDispositionPage`, `SparePartSalePage` | G-14, G-15, G-16 (Phases A/B/C) | KEEP_OPTIFLEET / ADJUST (evidence field only) | The condition-capture/used-processing/sell flows are already built (Phases A–C) and are materially more governed (maker-checker, audit) than VMS's tab view. One concrete gap remains: **no `evidence`/photo field on the return record**, even though the identical pattern exists elsewhere (`RoadTest`, `InspectionFinding`, `Breakdown`, `WarrantyClaim` all have an `evidence` column) — queued ADJUST, not yet implemented. |
| Tools / Tool Box | tool inventory; toolbox+assignee+tools | none — zero code trace | — | DEFER | Whole new custody/assignee subsystem (Tool, ToolBox, assignment). No existing OptiFleet analog to extend without inventing a data model. |
| Stock Request | Requestor; linked Work Order; requested items after WO selection | `PurchaseRequest` (`source_type='WORK_ORDER'` enum value exists, unused/unwired) | G-08 (partial) | ADJUST (queued, not yet implemented) | The demand-signal concept already exists (`PurchaseRequest` + `StockTransfer.REQUESTED`, confirmed sufficient in Phase G) — the specific missing piece is a `work_order_id` FK plus a WO-scoped item picker on `PurchaseRequest`, using the already-unwired `WORK_ORDER` source type. Bounded, no new subsystem. G-08's other clause (line-level hold/reject-reason) is separate and also still open. |

### 7. Partner Management

| VMS page | VMS §3/§4 observation | OptiFleet page/route | Gap ID | Decision | Reason / evidence |
|---|---|---|---|---|---|
| Supplier | Identity/contact/**Province/City/Account Holder/Account Number/Bank**/tax/type/description | `PartnerListPage`/`SupplierListPage`, `Partner` model | **G-42** | ADJUST (queued, not yet implemented) | Phase G added a Supplier-*filtered view* of `Partner` but not the missing fields themselves. Province/City/Account Holder/Account Number/Bank/Description all follow patterns `Partner` (and `Tenant`, for Province/City) already use elsewhere — bounded field additions, no new policy. **Supplier "Type" checklist** (Oil/Spareparts/Tires and Wheels/Attachment/Optional Accessories) is explicitly **DEFERRED**: it conflicts with OptiFleet's existing single-select `partner_type` enum used for eligibility gating elsewhere (e.g. `TireService::ELIGIBLE_SERVICE_PARTNER_TYPES`), and reconciling a multi-select taxonomy with a single-select gating enum is a real design decision, not a field addition. |
| Purchase Order | Tabs PO/Received PO; product compatibility filters; activity log | `PurchaseOrderListPage`/`PurchaseOrderDetailPage` | G-04 (PO print), G-06 | ADJUST — PO print done this session; PO tiered approval still open | **PO print wiring closed this session** (same class of bug as G-04, found by applying the Gap Report's own §33 "check for elsewhere" guidance — backend `print()` endpoint pre-existed and was untested/unreachable from the UI). Product category/brand/model **compatibility filter in the item picker** is a queued ADJUST (frontend-only, using existing Product/VehicleBrand/VehicleModel data). G-06 (tiered/threshold approval) remains open — no concrete tiers/thresholds exist in any available material; fabricating them was explicitly declined. |
| Workshop Partner | contact/bank/tax/description | `PartnerListPage` (generic Partner, no Workshop-specific memo/invoice flow) | G-13 | DEFER | See below (Maintenance Memo / Workshop Invoice) — Workshop Partner's own contact/bank/tax fields largely overlap the Supplier gap (G-42) and would be closed by the same field-addition work if applied to `Partner` generally, but the *cycle* built around it (memo → invoice → payment) is the substantive gap. |
| Maintenance Memo | partner/unit/date/problem/photo/condition/checklist/priority; Save and Print | none — zero code trace as a distinct document type | G-13 (part) | DEFER | No "Memo" document type exists between Work Order and Workshop Invoice. Building it would require deciding its relationship to `WorkOrderExternalService` (already built in Phase G for towing/service-provider requests) — could plausibly extend that model rather than duplicate it, but that's a design decision, not a bounded field fix. |
| Workshop Invoice | list/detail; empty in sample tenant | none | G-13 (part) | **BLOCKED** | A true Workshop Invoice entity would require inventing settlement/accounting policy (how is it priced, approved, paid, reconciled against `PurchaseOrder`/`GoodsReceipt`), matching this project's own prior explicit refusal to invent settlement behavior for Sell Sparepart. Needs a finance/accounting policy owner, not an engineering decision. |

### 8. Audit

| VMS page | VMS §3 observation | OptiFleet page/route | Gap ID | Decision | Reason / evidence |
|---|---|---|---|---|---|
| History | Search/Filter/pagination; cross-module activity | Per-domain `Auditable` trait + audit log views embedded in each domain's detail page (no single unified page) | — | DEFER | The underlying data already exists (audit trail via `Auditable`, visible per-domain). A unified cross-module History page is a real, bounded-but-not-trivial UI project (needs a merged/paginated cross-table query or a read model) — worth doing, but is a scoped feature addition, not a quick ADJUST, so it is queued for explicit prioritization rather than implemented unilaterally this session. |

### 9. Access Management

| VMS page | VMS §3/§4 observation | OptiFleet page/route | Gap ID | Decision | Reason / evidence |
|---|---|---|---|---|---|
| Access Features (Role) | Role list/Add; permission tree; **Select All Permissions** | `RolesPage` | — | ADJUST (queued, not yet implemented) | Pure UI convenience (a "select all" button over the existing permission checkbox tree) — no backend change, no policy question. |
| Company | profile; integration flags; enabled modules; approval flow; **first PIC account** | `CompanyProfilePage` (`/app/account/company`) | **G-39** | ADJUST — profile fields done in Phase G | Phase G added Tenant/Company profile fields mirroring `Partner`'s established shape (Address/Phone/Province/Fax/Email/City/Website). **Not verified**: whether the VMS's specific "PIC name/email/role/phone/password/photo" sub-block was replicated as named PIC fields, or just general contact fields — flagged in `IMPROVEMENT_CONTEXT.md` Known Blockers for confirmation. Combining company provisioning with first-admin-user creation in one form (VMS's own pattern) is explicitly *not* copied — OptiFleet's separate Tenant-provisioning/User-creation flow is safer and more auditable, matching the VMS document's own §34 "Sedang" (medium-severity) criticism of VMS's combined form. |
| User | New User; Add Worker/Role inline; active toggle | `UserListPage`, `WorkerListPage` (linked via `workers.user_id`, G-38) | G-38 (partial) | KEEP_OPTIFLEET / ADJUST done | User↔Worker link fixed in Phase G (see corrected G-38 mapping). Inline Worker/Role creation from the User form is a VMS UI convenience not replicated — OptiFleet's separate Worker/Role management pages are KEEP_OPTIFLEET (more auditable, matches the project's general preference for explicit master-data management over inline creation). |

## Implemented Adjustments (this session, Final Reconciliation phase)

1. **G-04 — Work Order print unwired.** `WorkOrderDetailPage.tsx`: added a
   "Print" button (gated by `work_order.view`) calling the pre-existing,
   already-tested `GET /work-orders/{workOrder}/print` endpoint
   (`WorkOrderController::print()`), blob-fetched and opened via
   `window.open` — same pattern already established in
   `AccountInvoiceDetailPage.tsx`. No backend change.
2. **Same-class fix on Purchase Order** (not separately numbered in the
   gap register, found by applying §33's "check for elsewhere" guidance):
   `PurchaseOrderDetailPage.tsx` — identical Print button wired to the
   pre-existing `PurchaseOrderController::print()` endpoint.
3. **G-09 — Rim entity.** Full stack: migration (`rims` table, tenant-
   scoped, soft-deletes), `Rim` model (`Auditable`, `BelongsToTenant`),
   `StoreRimRequest`/`UpdateRimRequest`, `RimController` (tenant-scoped
   CRUD), `rim.view`/`rim.manage` permissions, `RimTest.php` (5 tests / 14
   assertions, all passing — create/update/delete, per-tenant unique
   code, search, tenant isolation, permission gating), `RimsPage.tsx`
   (list + create/edit modal), route + "Tire Management" nav entry.
4. **Phase G G-ID documentation correction** — see
   `IMPROVEMENT_CONTEXT.md`'s "G-ID correction" table. No functional code
   changed; corrects which real gap each already-shipped Phase G batch
   actually closed, and surfaces which real gaps (G-01, G-06, G-08 partial,
   G-12, G-13, G-42, G-41 partial) were left open by Phase G despite the
   original Phase Status row implying full G-01–G-13/G-38–G-43 closure.

## Queued ADJUST items (identified, not yet implemented this session)

Small, bounded, policy-free — safe to implement in a following batch:
- Uom `description` field (completes G-41).
- Worker list Filter UI + Pagination UI (backend already supports both).
- Role "Select All Permissions" button.
- Wheel Configuration Edit/Delete endpoints + Search/Filter UI + computed
  Total-Axles/Total-Wheels/Config-Code display.
- Tire installation wheel-position dropdown (backend validation exists;
  frontend still free-text).
- Sparepart Return `evidence`/photo field (established pattern to copy
  from `RoadTest`/`InspectionFinding`/`Breakdown`/`WarrantyClaim`).
- Purchase Order item-picker category/brand/model compatibility filter
  (frontend-only).
- Stock Request: `work_order_id` FK + WO-scoped item picker on
  `PurchaseRequest` (completes G-08's first clause; `source_type=
  'WORK_ORDER'` enum value already exists, unused).
- Supplier/Partner Province/City/Account Holder/Account Number/Bank/
  Description fields (completes G-42, excluding the deferred Type
  checklist).

## Explicitly DEFERRED (needs a real product/business decision)

- Engine Model / Engine Type / Work Shift — whole new subsystems, zero
  existing code trace.
- Tool / Tool Box — new custody/assignee subsystem.
- Maintenance Package/Interval/Item admin UI (G-01) — needs a data-model
  decision, not a field addition.
- Bay Type master / capacity_unit / combined Bay+WO+Maintainer allocation
  (G-12) — new Workshop/Bay subsystem design.
- Supplier "Type" checklist — conflicts with the existing single-select
  `partner_type` eligibility-gating enum; needs a taxonomy decision.
- Dashboard KPI overhaul (most-used parts, nearest-schedule, scheduled-
  vs-WO, most-costly-vehicle, maintainer-performance rankings) — scope
  decision for the analytics/intelligence roadmap.
- Unified cross-module History page — data already exists per-domain;
  building a merged view is a real feature project, not a quick fix.
- Scheduled Maintenance manual create/reschedule/cancel/delete — would
  reverse an already-made architectural choice (policy-driven
  auto-generation); needs explicit re-decision, not a default.
- SKU auto-generation — needs a numbering-policy decision via the
  existing `NumberingConfiguration` mechanism, not a fabricated format.

## Explicitly BLOCKED

- **Workshop Invoice** (settlement/accounting policy) — same class of
  refusal as the project's prior Sell Sparepart decision.
- Anything touching Tire scoring/disposition thresholds or BD-6
  precedence rules — Phase F's "framework complete, configuration not
  approved, production scoring not enabled" status is preserved verbatim;
  this reconciliation phase made zero changes to scoring/disposition code.

## Not yet field-audited (flagged, not silently skipped)

The following pages received a page-level pass (confirmed the OptiFleet
equivalent exists and is at least as capable) but not the full VMS §4
field-by-field comparison this matrix format implies for a "complete"
audit: Vehicles (technical spec column list), Tire (tube type/pattern
1:1 field check), Tire Lifecycle tabs, Products (full §4 field list).
If exact VMS-to-OptiFleet field parity matters for any of these beyond
what's already noted, that is the natural scope for a follow-up session
with a narrower, single-domain focus.
