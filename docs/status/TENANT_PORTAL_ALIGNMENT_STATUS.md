# Tenant Portal Requirement Re-Audit — Status

Tracks reconciliation of 5 requirement documents against the OptiFleet-v2
repository baseline. This is a separate initiative from the Phase 6/7
Analytics/Intelligence track (see PHASE6_STATUS.md / PHASE7_STATUS.md) and
uses its own batch numbering (Batch 1-8), per explicit owner instruction.

## Documents Audited

1. Enhancement OptiFleet Tenant Portal - Planning and Schedule
2. Next Improvement Tenant Portal - Products
3. Next Improvement Tenant Portal
4. Perbaikan Tenant Portal - Work Order Status External dan Workshop Invoice
5. Improvement OptiFleet - Maintenance Request dan Work Order

## Conflict / Supersession Resolution

| Feature | Doc A | Doc B | Resolution |
|---|---|---|---|
| Tire specification | "Next Improvement Tenant Portal" (simple, no Vehicle Group split) | "Next Improvement Tenant Portal - Products" (Vehicle Group Car/Truck&Bus split, Load Index/Speed Rating master tables, derived Tire Size/Max Load/Max Speed/Load Range/TRA Profile/Purpose) | **Doc B (Products) wins** — already implemented exactly this way (tire_load_indices, tire_speed_ratings, tire_ply_ratings, tire_tra_codes, tire_tra_star_ratings tables; ProductSpecificationService.php derivation logic). Confirmed as the accepted resolution from a prior session. |

## NEEDS_CONFIRMATION (flagged, not blocking other work)

1. **Consumable "Specification/Grade" conditional-mandatory trigger undefined.**
   Doc says Conditional Mandatory "for oil, coolant, brake fluid, grease, and
   certain chemicals" but no discrete field (e.g. a Consumable sub-type)
   exists to key the condition off. Left Optional (prior session's decision,
   documented with a TODO in ConsumableFields). Flagging for owner decision;
   not re-litigating without an answer.

## Gap Analysis Summary (by area)

### Maintenance Request + Inspection — mostly ALREADY_COMPLIANT
- Assessment 14-group table, GOOD/ATTENTION/REPAIR_REQUIRED/CRITICAL_UNSAFE/NOT_APPLICABLE
  statuses, Draft-only mutability, snapshot-safe Inspection templates, non-removable
  Odometer item, odometer-floor-guarded Vehicle update: all correct.
- GAPS: List page missing Created At / Submitted by / Actions(Create WO) columns.
  NEED_INFORMATION status still live in DB enum/seeders/dashboard query/frontend
  filter despite being functionally retired — needs full cleanup. Assessment
  Save/Clear currently ties editability to request.status===DRAFT rather than
  implementing the actual Save-button-becomes-Edit-button toggle the doc
  specifies (needs a `has been saved at least once` local/derived flag).
  StatusBadge missing per-status colors. Frontend source_type type missing
  INTELLIGENCE.

### Work Order — PARTIALLY_COMPLIANT, Finding 422 root cause identified
- All 15 statuses + execution_mode=EXTERNAL exist. maintenance_type correct
  (Corrective/Breakdown only in create dropdown — CONFIRM: doc also excludes
  Preventive from manual creation, matches). Diagnosis/Corrective Action
  optionality already correct. Two Planned Parts / Part Requests tabs already
  exist. Est. Labor Cost auto-calc from hourly rate already correct (at Job
  level). Maintenance Memo document generation exists.
- ROOT CAUSE (Finding 422): `WorkOrderExecutionService::assertExecutable()`
  gates addFinding/addDiagnosis/addCorrectiveAction/addJob/assignMechanic/
  addPlannedPart all through ONE shared status set
  (ASSIGNED/SCHEDULED/IN_PROGRESS/ON_HOLD/WAITING_PART/REWORK), excluding
  DRAFT. But the doc requires Findings/Diagnosis addable ONLY at DRAFT
  (Add-button hidden after), while Jobs/Mechanic/Planned Parts must stay
  addable from DRAFT through IN_PROGRESS (Jobs: add-only, no edit/delete,
  once IN_PROGRESS). Fix: split into per-action allowed-status sets on the
  backend, mirror as frontend UI gates (currently the frontend shows Add
  forms unconditionally at every status with no gate at all).
- OTHER GAPS: No "Est. Number of Mechanic" / "Number of Mechanics" auto-count
  anywhere. No "Est. Total Hours" accumulation across Jobs. Overview tab
  Complaint section doesn't exist (doc wants it moved there for
  user-created WOs). No Last Odometer/Last HM label in Create WO modal.
  Consume action has no popup/Install-All/Installed-Qty — one-click consumes
  everything. Return popup is a single form (not split Unused/Used
  subtables), Condition values don't match doc's "New Good/New Faulty" vs
  "Used Good/Used Faulty", Evidence is a plain URL textfield not an image
  upload.

### Workshop Invoice + Work Authorization — largely ALREADY_COMPLIANT
- Status enums, action matrix, WAL generation/numbering (deliberate hyphen
  deviation, disclosed), Deliver/Acknowledge/Complete/Settle file
  constraints, Paid->WO Closed trigger, Cancel propagation (WO->Invoice):
  all correct.
- GAPS: "View History" popup does not exist anywhere despite being
  advertised in the allowedActions() matrix (no route, no UI).

### Product — PARTIALLY_COMPLIANT, biggest gap = Edit
- General Information, all 6 Item Type spec tables (Sparepart/Consumable/
  Rim/Tire/Tool/Equipment) with correct M/O/C flags, Tire Vehicle Group
  split with derived fields: all correct on CREATE.
- MAJOR GAP: Product Edit does NOT reconstruct the dynamic Item-Type spec
  form at all — `ProductController::update()` only touches generic physical
  columns; none of the 6 spec tables are editable post-creation, and
  Category/Subcategory/UOM/Default Storage Location can't be changed either.
- OTHER GAPS: No "Active" toggle UI anywhere (create or edit). Extra `OTHER`
  item type exists beyond the documented 6. No unified "Inventory
  Configuration" section (Min/Reorder/Max Stock don't exist on Product
  master data at all). Consumable SDS file has backend columns but no
  upload UI.

### Vehicle / Workspace / Worker / Master Data — mostly ALREADY_COMPLIANT
- Vehicle Brand/Model/Category dropdowns, Purchase Month/Year, Axles/Wheels/
  Empty/Load Weight order, Photo upload, Assignment branch names, Documents
  delete/preview, Product Categories superadmin-only, UOM Type of Measure,
  Vehicle Brand multi-select+logo-upload: all correct.
- GAPS: Worker Type master data fully built server-side but frontend still
  hardcodes the old enum (never wired) — FIXED in Batch 6.
  CORRECTION: "Component Groups has no New creation UI" was NOT a gap —
  doc c686cc8d Section 31 explicitly says "Sembunyikan tombol New Component
  Group" (hide the New Component Group button), and the button is indeed
  already absent. No change needed; my original audit misread this as a
  missing feature rather than a compliant hidden one.
  Workspace "Capacity Unit" is
  implemented as two separate controls (numeric Capacity + multi-select
  Vehicle Categories checklist) rather than one field, though this
  functionally satisfies the underlying intent.

### Maintenance Packages + Planning & Schedule + Company Profile — ALREADY_COMPLIANT
- PREVENTIVE/PERIODIC period-by restrictions, Threshold + Equivalent
  Threshold (3 fields), Schedule Period, checkbox-based item selection with
  Activate gate, Edit Items/Update Items/Cancel trio reverting to ARCHIVED
  ("Inactive") on change, package snapshot frozen at first schedule
  creation, Add New Schedule with branch-scoped vehicle / blocked-date
  calendar / PERIODIC+ACTIVE package filter / working-days-aware date calc,
  Workshop Working Days in Company Profile (5/6/7, referenced not
  hardcoded): all correct.
- MINOR GAP: legacy Add-Item/Add-Interval popup UI still coexists (for
  non-PREVENTIVE/PERIODIC legacy types only, not removed since those types
  still need their own item-add mechanism).

### Navigation / Layout — mostly ALREADY_COMPLIANT
- Navbar tenant name moved left, Account/Organization/Access moved to
  custom hover-dropdowns (NavDropdown), Audit Log positioned after
  Configuration History with visual gap, Breadcrumb + BackButton components
  already built and used app-wide (react-router-dom v7): all correct.
- GAP: `Tenant.logo_url` exists as a DB field but is a plain URL string
  input, wired only into the Company Profile page's own preview — never
  into the actual sidebar `Logo.tsx` or the browser favicon. No file-upload
  pipeline for it (VehicleBrand's logo upload is the pattern to copy).
  Favicon/title are fully static in index.html.

## Batch Plan & Progress

| Batch | Scope | Status |
|---|---|---|
| 1 | Maintenance Request list columns, NEED_INFORMATION cleanup, Assessment Save/Edit toggle | DONE (commit pending push) |
| 2 | Work Order per-action status gates (422 fix) | DONE (commit pending push) — Overview restructure, Est. Number of Mechanic/Total Hours, Consume/Return popup redesign still open, see detail below |
| 3 | Workshop Invoice View History | DONE (commit pending push) |
| 4-5 | Product Edit dynamic form, Active toggle | DONE (commit pending push) — Inventory Configuration section still open |
| 6 | Worker Type frontend wiring | DONE (commit pending push) — Component Group "New" button correction below |
| 7 | Maintenance Packages + Planning & Schedule | REVIEWED, NO CHANGE NEEDED — already fully compliant per audit; the "legacy popup" note is intentional coexistence for non-PREVENTIVE/PERIODIC package types, not a gap |
| 8 | Tenant logo upload + favicon wiring | DONE (commit pending push) — Inspection/Navigation items reviewed separately, see detail |

## Batch 1 Detail (Maintenance Request + Assessment)

Changes:
- List page: added Created At, Submitted by, Actions (Create Work Order for
  Approved) columns. Backend: MaintenanceRequest.requestedByUser relation,
  eager-loaded in index/show.
- NEED_INFORMATION cleanup: removed from frontend filter bar and from
  DashboardController's open-requests count query (DB enum value and legacy
  read paths intentionally left alone per the service's own docblock).
- Assessment section: implemented the actual Save-becomes-Edit /
  Edit-becomes-Save toggle with Clear only visible while editing (was
  previously just tied to request.status===DRAFT with no local toggle).
  Workflow Actions card now gated behind "assessment has been saved at
  least once" for Draft, User-sourced requests specifically.
- Added InspectionSourceSection: Inspection-sourced requests now show the
  originating Inspection's frozen checklist + Recorded Findings read-only,
  instead of the (User-only) Assessment section.
- Cancel now requires a reason via a confirmation modal (Yes/No), both
  frontend and backend (previously optional, undocumented). Added
  `cancellation_reason` column (migration
  2026_09_27_000003_add_cancellation_reason_to_maintenance_requests.php)
  since `review_note` was reserved for Approve/Reject.

Tests: MaintenanceRequestAssessmentTest (17/17 incl. 2 new), Maintenance
RequestAndBreakdownTest (6/6), DashboardSupplyChainTest (1/1), WorkOrderTest
spot-check (8/8) — all PASS. Full-suite/parallel run NOT RUN: ext-mongodb is
unavailable in this sandbox and installing it is blocked by the egress
policy (matches the Phase 6/7 precedent); verified instead by temporarily
relocating the 3 Mongo-touching migrations out of database/migrations/ for
the test run, then restoring them byte-for-byte (confirmed via git diff)
before committing. Frontend: tsc -b clean, oxlint clean (pre-existing
warnings only, all in files I did not touch), production build succeeds.

## Batch 2 Detail (Work Order Finding 422 remediation)

Root cause confirmed and fixed: `WorkOrderExecutionService` used ONE shared
status gate (`assertExecutable`) for six unrelated actions. Replaced with
three purpose-built gates:
- `assertFindingScopeEditable()` — DRAFT only. Findings/Diagnosis/
  Corrective Actions: add AND delete (new DELETE endpoints added — these
  didn't exist before despite the doc explicitly requiring "dapat
  dihapusnya kembali" for all three).
- `assertPlanningEditable()` — DRAFT through REWORK (new, broader).
  Jobs/Planned-Parts add, Mechanic assign (MechanicAssignmentService now
  injects WorkOrderExecutionService and gates on this — previously
  completely ungated).
- `assertExecutable()` — unchanged, original ASSIGNED..REWORK set. Still
  used by Part Requests, Reserve/Issue/Return, External Services,
  Additional Work (none of these are addressed by the 5 requirement docs;
  left exactly as before to avoid unrelated regressions).
- All three gates now also reject execution_mode=EXTERNAL outright — a gap
  that would otherwise have let a Draft External-mode WO reach internal-only
  endpoints once DRAFT was added to the broadened gates.

Frontend: ComplaintTab/DiagnosisTab Add forms + new Delete buttons now
gated to DRAFT; JobsTab/MechanicTab/PlannedPartsTab Add forms gated to the
broader Planning window; Reserve/Issue/Consume/Return hidden entirely
before ASSIGNED (doc: these must not even render during Draft).

Tests: WorkOrderExecutionTest, WorkOrderLifecycleGapsTest,
ExternalWorkOrderTest, WorkOrderPartRequestTest, WorkOrderStockIntegrationTest,
MechanicHourlyRateAndLaborCostTest, WorkOrderTest, WorkOrderClosureGuardTest,
ExternalWorkOrderInvoiceCompletionTest, ExternalWorkOrderInvoiceTest,
WorkOrderExternalInvoiceTest, WorkOrderExternalServiceTest, WorkerUserLinkTest
— 119/119 PASS (run individually/sequentially per-file to avoid a Postgres
test-DB deadlock this sandbox hits under a combined multi-file `--filter`;
not related to my changes). Two pre-existing tests updated to add
Findings/Diagnosis while the WO is still Draft (the behavior the doc
actually requires) instead of after driving it to In Progress (the old,
incorrect assumption those tests encoded). Frontend: tsc/oxlint/build clean.

REMAINING Batch 2 gaps (not yet done, deferred to a follow-up push):
Overview tab restructuring (move Complaint section there for user-created
WOs, move Est. Labor/Parts Cost fields to their own tabs), Est. Number of
Mechanic + Est. Total Hours accumulation, Consume popup (Install All +
Installed Qty), Return popup redesign (split Unused/Used subtables, renamed
Condition values, actual image upload instead of a URL textfield),
Planned-Parts-vs-Request-Parts architecture (doc wants Request Parts to
only appear starting In Progress; current app has both as always-visible,
architecturally distinct tabs from a prior session — reconciling this is
a larger design question, not a one-line fix).

## Batch 3 Detail (Workshop Invoice View History)

Gap: `ExternalWorkOrderInvoiceController::allowedActions()` advertised a
"View History" action in its response matrix, but no route or UI backed
it — the same generic `AuditLog` mechanism already used elsewhere
(`Auditable` trait, auto-recorded on every mutating action) was simply
never surfaced for this resource.

- `ExternalWorkOrderInvoiceController::history()` (new): queries
  `AuditLog::query()->where('resource_type', 'WorkOrderExternalInvoice')
  ->where('resource_id', $externalInvoice->id)`, ordered newest-first, no
  new table or write path needed since `Auditable` was already recording
  these events.
- Route: `GET /app/external-work-order-invoices/{externalInvoice}/history`
  (`permission:external_work_order_invoice.view` — read-only, same gate as
  viewing the invoice itself).
- Frontend `ExternalWorkOrderInvoiceListPage.tsx`: added a "View History"
  button opening a modal listing the audit trail (actor, action, timestamp,
  changed fields).

Tests: new case in `ExternalWorkOrderInvoiceTest` asserting the history
endpoint returns the recorded lifecycle events in order and is tenant/
permission-scoped like the rest of the resource. Re-ran
`ExternalWorkOrderInvoiceTest` full file — PASS, no regressions. Frontend:
tsc/oxlint/build clean.

## Batch 4-5 Detail (Product Edit Dynamic Form)

The single biggest gap in the whole audit: `ProductController::update()` never
touched Category/Subcategory/UOM/Default Storage Location or any of the 6
Item Type spec tables — only generic physical columns (weight, dimensions,
material) were editable post-creation.

- `ProductSpecificationService::persist()` now uses `updateOrCreate()` keyed
  on `product_id` for all 6 spec tables instead of `create()`, so the exact
  same method now serves both Create (no row exists yet) and Edit. Added an
  `$includeCompatibilities` flag (Sparepart/Rim only) so Edit's dynamic form
  never touches Vehicle Compatibility — that stays on its own existing
  add/remove UI on the Product detail page, per Section 17's Item-Master-vs-
  Asset-data boundary applied to relational data too.
- `ProductController::update()`: accepts `product_category_id`/`uom_id`
  (validated against the product's own immutable `product_type`, same
  category-matches-type check as Create), and a `spec` payload — only
  validated/persisted when the key is actually present, so a bare status
  toggle doesn't have to resend the whole form and can't accidentally null
  the spec out. Item Type and Item Code both stay immutable on Edit (Section
  13's read-only precedent extended to Item Type, since changing it would
  mean an entirely different spec table — a new-product decision, not an
  edit).
- Frontend: new `EditProductModal.tsx` reuses the exact same field
  components as `CreateProductModal.tsx` (exported, not duplicated) so the
  two forms structurally cannot drift apart. Hydrates General Information,
  the Active toggle (previously had no UI anywhere), the legacy physical-
  attribute fields (kept, not dropped, to avoid a backward-compatibility
  regression — collapsed into a details/summary so they don't crowd the
  primary form), and the correct per-Item-Type spec section with existing
  values — including live-recomputing Tire's derived preview (Tire Size /
  Max Load / Max Speed / etc.) as the user edits. Category/Subcategory
  hydration walks the category's own parent_id; Default Storage Location
  hydration walks Bin->Rack->Zone->Warehouse once client-side (no
  single-record lookup endpoint exists for these).

Tests: new ProductEditDynamicFormTest (8/8: spec update, compatibility
untouched, spec-omitted update leaves spec alone, validation parity with
Create, category/uom/bin change, cross-item-type category rejected,
product_type ignored if sent, Tire spec update + derived-value recompute).
Full existing Product suite re-run: ProductDynamicSpecificationTest (28),
ProductTest (7), ProductFoundationMasterDataTest (9),
ProductCategoryAndUomTest (5), ProductsCyclePhase5SupportingModulesTest (7),
PlatformProductCategoryTest (5) — 61/61 PASS, no regressions. Frontend:
tsc/oxlint/build clean.

REMAINING: Inventory Configuration section (Stock Tracking/Min/Reorder/Max
Stock — these don't exist on Product master data at all, would need a
schema change; deferred), extra `OTHER` item type beyond the documented 6
(flagging, not removing — unclear if used elsewhere), Consumable SDS file
upload UI (backend columns exist, no upload control).

## Batch 6 Detail (Worker Type wiring)

Backend already had `worker_types` master-data table + `WorkerTypeController`
+ an additive `workers.worker_type_id` FK (seeded from the legacy 5-value
enum) built in a prior session, but `WorkerController::store()`/`update()`
never accepted `worker_type_id` at all, and the frontend still hardcoded
the old enum array.

- `Worker::workerType()` renamed to `workerTypeMaster()` before wiring
  anything up: Eloquent snake_cases a relation name to build its JSON key,
  and `workerType` -> `worker_type` collides with (and silently overwrites)
  the pre-existing legacy string column the moment it's eager-loaded. Fixed
  before it could ship as a real bug, not found by chance — traced through
  deliberately once I noticed the naming pattern.
- `StoreWorkerRequest`/`WorkerController::update()`: `worker_type_id` now
  accepted (tenant-or-system scoped); `worker_type` (legacy) and
  `worker_type_id` are `required_without` each other, so old API callers
  keep working unchanged. `deriveLegacyWorkerType()` mirrors the legacy
  column automatically when the selected type's code is one of the
  original 5 (keeps any existing worker_type-keyed report/query working);
  a custom tenant-defined type just leaves the legacy column at its DB
  default, since it's no longer authoritative once worker_type_id is set.
  `store()` uses `fresh()` after create (not `load()`) so the response
  actually reflects that DB-applied default rather than a stale null.
- Frontend `WorkerListPage.tsx`: Worker Type filter, Create form, and List/
  Detail display all now source from `GET /app/worker-types` instead of a
  hardcoded array; display prefers `worker_type_master.name`, falling back
  to the legacy string for any pre-existing record without a linked type.

Tests: new WorkerTypeWiringTest (6/6: create+legacy mirror, relation
doesn't collide with the column, custom type, filter by worker_type_id,
update, cross-tenant type rejected). Re-ran WorkerUserLinkTest (8),
ProductFoundationMasterDataTest (9), ProductsCyclePhase5SupportingModulesTest
(7), WorkOrderExecutionTest (6, exercises makeWorker()) — 30/30 PASS, no
regressions. Frontend: tsc/oxlint/build clean.

## Batch 8 Detail (Tenant logo upload + favicon wiring)

Gap identified in the original audit: `Tenant.logo_url` existed as a DB
column but was only a plain URL text input on the Company Profile page —
never wired into the sidebar `Logo.tsx` or the browser favicon, and with no
actual file-upload pipeline (VehicleBrand already has one to copy the
pattern from).

Architectural decision: VehicleBrand's logo pattern serves the file from
the private `local` disk through an authenticated controller action
(`Storage::disk()->response()`), which works for `<img>` tags fetched with
an Authorization header but cannot work for a browser `<link rel="icon">`
tag (no custom headers on that request) or for the SPA's very first paint
before `/auth/me` resolves. So the tenant logo is stored on Laravel's
`public` disk instead and served via a genuinely public URL. Rather than
add four new logo_disk/logo_path/logo_mime_type/logo_size columns (the
VehicleBrand shape), the existing `Tenant.logo_url` string column is reused
— the upload endpoint just writes the resulting public URL string into it,
avoiding an unnecessary schema change.

- `TenantLogoService` (new, `App\Domain\Identity\Services`): validates
  JPG/PNG and a 5MB cap (same limits as VehicleBrandLogoService), stores to
  `tenant-logos/{tenant_id}/{uuid}.{ext}` on the `public` disk, writes
  `Storage::disk('public')->url($path)` into `Tenant.logo_url`, and deletes
  the previous file once the new one is committed (mirrors the "delete
  after re-point" ordering used for vehicle photos/brand logos).
- `CompanyProfileController::uploadLogo()` (new) + `POST
  /app/account/company/logo` (multipart, `permission:company.update`,
  reuses the existing `company.update` gate rather than adding a new
  permission).
- `CurrentUserPresenter::memberships()`: added `tenant_logo_url` per
  membership so the sidebar/favicon have it globally on every page load
  (not just when visiting Company Profile), since `/auth/me` populates
  `AuthContext` once at session start.
- Frontend: `CompanyProfilePage.tsx` — the "Logo URL" text input is
  replaced with an actual file picker that uploads immediately on
  selection (its own request, decoupled from the text-fields Save button,
  so a slow/failed logo upload never blocks or gets tangled with the rest
  of the form); shows the current logo and an inline validation/upload
  error. `Logo.tsx` gained an optional `src` prop (defaults to the static
  wordmark, so the login screen and any tenant with no uploaded logo are
  unaffected). `TenantLayout.tsx` passes the active membership's
  `tenant_logo_url` into the sidebar `<Logo>` and added an effect that
  swaps every static `<link rel="icon">` href in `index.html` to the
  tenant's logo URL when one is set, restoring the original hrefs on
  unmount (covers logout back to the static login page).
- Deployment prerequisite (not committed code, cannot be verified in this
  sandbox): `php artisan storage:link` must be run once per environment so
  `public/storage` resolves to `storage/app/public` — without it, uploaded
  logo URLs 404 even though the upload itself succeeds.

Tests: new `CompanyProfileTest` cases — upload replaces the previous file
and returns a `/storage/tenant-logos/...` URL, disallowed MIME type
rejected (422), denied without `company.update` permission (403). Ran
alongside existing `CompanyProfileTest` (4), `AuthTest` (10),
`VehicleBrandAndModelTest` (7), `VehicleTest` (18, exercises the same
UploadedFile/Storage patterns) — 42/42 PASS, no regressions. `pint --test`
clean on all changed/new PHP files. Frontend: `tsc --noEmit`, `oxlint`
(project-wide — pre-existing warnings only, none in changed files), and
`vite build` all clean.

MongoDB: NOT RUN — `ext-mongodb` is unavailable in this sandbox and
network-blocked from installing (org policy). Verified instead by
temporarily relocating the 3 Mongo-touching migrations out of
`database/migrations/`, running the full test sweep above, then restoring
them byte-for-byte (confirmed via `git status --porcelain` showing zero
diff) before committing.

## Continuation: closing REMAINING items (owner-directed re-audit)

Per explicit owner instruction, re-auditing every item the Final Report
listed as REMAINING/Deferred/NEEDS_CONFIRMATION against the original 5
requirement documents (re-extracted from the prior session's scratchpad,
byte-identical source text) and the actual current repository code —
not the Final Report's own summary of itself. New batch numbering
continues from 9. Two re-audit findings worth flagging up front:

- The prior Final Report's Product-module conclusion "No unified
  Inventory Configuration section (Min/Reorder/Max Stock don't exist on
  Product master data at all)" undersold the gap: Stock Tracking/Min
  Stock/Reorder Point/Max Stock are genuinely new fields not in the repo
  anywhere, but Serialized/Batch Tracked/Expiry Tracked/Installable/
  Checkout-able/Maintainable/Calibratable — the other 7 rows of that same
  requirement table — are already fully implemented per-Item-Type (shared
  `products.track_serial_number`/`track_batch` columns plus each spec
  table's own `track_expiry`/`checkout_required`/`calibration_required`/
  `maintenance_required`). Only the 4 new fields need work (Batch 12).
- Re-reading `WorkOrderListPage.tsx`'s Create Work Order modal directly
  (not the Final Report) found the Maintenance Type dropdown still
  offered all 5 types (PREVENTIVE/CORRECTIVE/BREAKDOWN/INSPECTION/
  CAMPAIGN), contradicting the Final Report's claim this was already
  "CONFIRM: doc also excludes Preventive from manual creation, matches."
  No test enforced it either. Fixed in Batch 9 — a concrete example of
  why this re-audit re-reads code rather than trusting prior summaries.

## Batch 9 Detail (Work Order Overview + estimation fields)

Re-read "Improvement OptiFleet - Maintenance Request dan Work Order" in
full (not just the Final Report's excerpt). Scope: Overview restructuring,
Complaint placement, Est. Number of Mechanic, Est. Total Hours, Estimated
Labor/Parts Cost becoming computed-only, Last Odometer/HM labels, Current
KM mandatory, Maintenance Type dropdown restricted to Corrective/Breakdown.

- `WorkOrder` model: added `estimatedTotalHours()` (sum of Jobs'
  `estimated_hours`) and `estimatedNumberOfMechanics()` (count of distinct
  currently-assigned, i.e. `unassigned_at IS NULL`, mechanics). Redesigned
  `computedEstimatedLaborCost()` to the doc's crew-cost formula — Est.
  Total Hours x the SUM of every currently-assigned mechanic's hourly
  rate — replacing the old per-job x per-job's-primary-mechanic
  "suggestion" formula a prior session built before this doc's exact
  formula was available. The legacy manually-entered `estimated_labor_cost`
  /`estimated_parts_cost`/`estimated_total_cost` columns and the
  `/work-orders/{id}/estimate` endpoint are left completely intact
  (existing data and any future direct API caller keep working) — only
  the frontend stops exposing manual entry for them, matching the doc's
  "tidak dapat di edit secara langsung oleh user" exactly without deleting
  a working, tested backend capability that isn't itself the problem.
- `WorkOrderController::show()`: appends `estimated_total_hours` and
  `estimated_number_of_mechanics` alongside the existing single-record-only
  `estimated_labor_cost_computed` (never on `index()`, same N+1-avoidance
  precedent).
- `StoreWorkOrderRequest`: `maintenance_type` restricted to
  `CORRECTIVE,BREAKDOWN` (Preventive is exclusively set by the Planning &
  Schedule conversion path, a different code path entirely, so this is
  safe); `current_odometer` changed from `nullable` to `required`.
- `WorkOrderService::create()`: when `current_odometer`/`engine_hour` are
  supplied, now actually updates the Vehicle's own fields — floor-guarded
  with the same `max(current, new)` pattern `VehicleReleaseService`
  already uses, so a WO can never move a vehicle's odometer backwards.
  Previously the submitted value was stored on the WorkOrder row only and
  never touched the Vehicle at all.
- Frontend `OverviewTab`: added Est. Number of Mechanic / Est. Total Hours
  rows; Estimated Labor/Parts/Total Cost rows now read the computed values
  (Total = Labor + Parts, summed client-side from two already-authoritative
  backend numbers — not a re-derivation of business logic). Removed the
  "Cost Estimate" manual-entry card entirely (doc: "Hapus section Cost
  Estimate dari tab Overview"). Complaint now renders in Overview only for
  a user-created WO (`!wo.maintenance_request_id`); a Maintenance-Request-
  converted WO instead shows a new `MaintenanceRequestSourceSection` that
  fetches the source request and branches on `source_type` — Assessment
  table for `USER`, the Inspection's frozen checklist + Recorded Findings
  (`InspectionSourceSection`, exported from `MaintenanceRequestDetailPage`
  for reuse) for `INSPECTION`. Previously Overview always rendered
  `AssessmentSection` unconditionally, which silently showed nothing
  useful for an Inspection-sourced request (no Assessment row ever exists
  for one).
- `ComplaintTab`: the static Complaint text block removed (moved to
  Overview); Findings section unchanged.
- `MechanicTab`: read-only "Number of Mechanics" and "Estimated Labor
  Cost" fields added below the assignments table (doc: "tidak dapat di
  edit secara langsung oleh user").
- `JobsTab`: "Est. Total Hours" accumulator display added above the job
  list.
- `WorkOrderListPage.tsx` Create Work Order modal: Maintenance Type
  dropdown restricted to Corrective/Breakdown; Current KM is now required
  (Create button disabled without it) with a "Last Odometer: <value>"
  label sourced from the selected vehicle; Current HM gained a matching
  "Last HM: <value>" label.

Tests: 2 new `WorkOrderTest` cases (maintenance_type restriction,
current_odometer required + floor-guarded vehicle update). Updated
`MechanicHourlyRateAndLaborCostTest::test_work_order_level_estimate_
aggregates_across_multiple_jobs` to
`..._uses_the_crew_cost_formula` — the old assertion (240.0000) encoded
the superseded per-job formula; the new one (450.0000 = 6h total x
$75/h combined crew rate) matches the doc's actual formula, plus new
assertions on `estimated_total_hours`/`estimated_number_of_mechanics`. 38
pre-existing Work Order creation calls across 15 other test files needed
`current_odometer` added now that it's required (mechanical, verified by
re-running every touched file); 2 of those also had their
`maintenance_type` changed from `PREVENTIVE` to `CORRECTIVE` since neither
test's actual assertion depended on the type. Full sweep after fixes:
`WorkOrderTest`(10), `WorkOrderExecutionTest`(6),
`MechanicHourlyRateAndLaborCostTest`(7), `QualityControlAndReleaseTest`(6),
`ConfigurationAuditAndRegressionTest`(5), `DocumentTemplateTest`(11),
`ExternalWorkOrderInvoiceCompletionTest`(6), `ExternalWorkOrderInvoiceTest`
(13), `ExternalWorkOrderTest`(21), `WorkAuthorizationLetterTest`(10),
`WorkOrderClosureGuardTest`(3), `WorkOrderExternalInvoiceTest`(6),
`WorkOrderExternalServiceTest`(12), `WorkOrderLifecycleGapsTest`(13),
`WorkflowMigrationTest`(4), `WorkshopInvoiceTest`(20),
`HistoryAndDowntimeTest`, `InventoryReturnClassificationTest`,
`MaintenanceRequestAndBreakdownTest`, `SparePartSaleTest`,
`UsedPartDispositionTest`, `WorkOrderPartRequestTest` (these last 6 call
`WorkOrderService::create()` directly, bypassing `StoreWorkOrderRequest`
entirely, so unaffected but re-run to confirm) — 163+ assertions across
these files, all PASS, no regressions. `pint --test` shows pre-existing
style debt in `WorkOrderService.php`/`WorkOrder.php`/2 test files
(confirmed via `git stash` that every flagged fixer predates this batch's
changes — left untouched, out of scope). Frontend: `tsc --noEmit` clean,
`oxlint` clean (no new warnings), `vite build` succeeds.

MongoDB: NOT RUN — same relocate-run-restore precedent as every prior
batch, verified zero diff before committing.

REMAINING from this doc, not yet done: the doc's extremely detailed
per-status Tab/button visibility matrix (nearly the entire rest of the
document — e.g. QC/Road Test tabs should only appear from QC_PENDING
onward, Workspace only from SCHEDULED onward, every tab's inputs read-only
once CLOSED/REJECTED/CANCELLED) is NOT implemented — today all
`INTERNAL_TABS` are always visible regardless of WO status (only the
External-mode split exists). This is a large, distinct body of work
discovered during this re-audit that was not in the owner's explicit
Batch 9-14 priority list; flagging it honestly here rather than silently
leaving it undiscovered. Consume/Return popup redesign and Planned-Parts-
vs-Request-Parts reconciliation are Batches 10-11, not yet done.
