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

*(none currently open from the original audit — see "OWNER DECISION 1 — RESOLVED" below for
how the Consumable Specification/Grade item was closed. New items discovered during the
Return/Removed-Component work are tracked in that batch's own Detail section instead of here.)*

## OWNER DECISION 1 — RESOLVED: Consumable Specification/Grade trigger = Category/Subcategory

Previously NEEDS_CONFIRMATION ("Doc says Conditional Mandatory 'for oil,
coolant, brake fluid, grease, and certain chemicals' but no discrete field
exists to key the condition off"). Owner decision: driven by the Product's
own **Category/Subcategory**, not a new Consumable sub-type, and not a
hardcoded frontend/backend string comparison against a category name.

Architecture chosen: `product_categories` (Category and Subcategory are
both rows in this one self-referencing table; a Product stores a single
`product_category_id` pointing at whichever leaf the user picked) gains a
new Superadmin-managed boolean, `requires_specification_grade`. This was
the smallest correct escalation — `code`/`name` were ruled out first
because Product Categories are platform (Superadmin-only) master data
with no fixed, code-enumerable taxonomy today (no "Oil"/"Coolant"/etc.
subcategories are seeded anywhere in the repository yet), so a hardcoded
code list would be exactly the fragile string-matching the owner
instructed against. A boolean flag on the category row itself is stable
master-data metadata instead.

- Backend: `ProductSpecificationService::specificationGradeRequired()`
  looks up the resolved `product_category_id`'s flag and threads it into
  `validateConsumable()`'s `grade_specification` rule
  (`required`/`nullable`). `ProductController::store()` now passes
  `product_category_id` into the general input `specs->validate()` sees;
  `update()` falls back to the product's EXISTING category when the edit
  request doesn't touch `product_category_id` at all, so a spec-only edit
  still enforces the right rule instead of silently treating "no category
  in this request" as "never required" — see the dedicated regression
  test for this exact case.
- Platform `StoreProductCategoryRequest`/`UpdateProductCategoryRequest`:
  accept the new flag so Superadmin tooling can set it per category/
  subcategory.
- Frontend: `gradeSpecificationRequired()` (exported from
  `CreateProductModal.tsx`, reused by `EditProductModal.tsx`) mirrors the
  backend's own leaf-resolution exactly (`subcategoryId || categoryId`),
  marks the field `required` on the FormField when true — never hides it
  when optional, per the owner's UI instruction. Edit's existing Category/
  Subcategory hydration effect already recalculates on every render, so
  changing Category on Edit live-updates the requirement with no
  additional wiring; an Edit that never touches Category/spec preserves
  the existing Specification/Grade value untouched (full-resubmit is the
  established Edit pattern for every other spec field already).
- Data: no "Oil"/"Coolant"/"Brake Fluid"/"Grease" subcategories are seeded
  with the flag set anywhere yet — that is a Superadmin content/data task
  (creating the actual category rows via the Platform endpoint), not a
  code gap; the mechanism is fully implemented and tested against
  synthetic categories.

Tests: 4 new cases in `ProductDynamicSpecificationTest` — required when
flagged (missing -> 422, present -> 201), optional when not flagged,
Edit recalculates on Category change (old category's rule -> reject,
new category + value -> accept), Edit omitting Category still enforces
the product's existing category's rule and preserves the existing value
when resubmitted. Full regression:
`ProductDynamicSpecificationTest`(32), `ProductCategoryAndUomTest`(5),
`PlatformProductCategoryTest`(5), `ProductEditDynamicFormTest`(8),
`ProductTest`(7), `ProductFoundationMasterDataTest`(9),
`ProductsCyclePhase5SupportingModulesTest`(7) — 73/73 PASS, no
regressions. `pint --test` clean (pre-existing fixer flags on
`ProductSpecificationService.php`/`ProductController.php` confirmed via
`git stash` to predate this change). Frontend: `tsc --noEmit` clean,
`oxlint` clean, `vite build` succeeds. MongoDB: NOT RUN, relocate-run-
restore precedent followed, zero diff confirmed.

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

## Batch 10 Detail (Return evidence upload backend — part 1 of 2, PAUSED)

Scope: Consume popup (Install All/Installed Qty) + Return popup redesign
(Unused/Used split, Condition values, JPG/PNG upload replacing the
Evidence URL text field). Paused mid-batch per explicit owner instruction
before the frontend half was started — see Pause Checkpoint below for the
exact resume point.

**Architecture note surfaced during this batch (unresolved, disclosed
rather than guessed):** re-reading the doc's Return popup section against
the actual repository found that the existing (tested, working)
`WorkOrderPartService::returnPart()`/`WorkOrderPartReturn` architecture
treats "Unused" vs "Used" as a CONDITION CLASSIFICATION the returner
assigns to any returned-but-not-yet-consumed issued quantity (all capped
by one shared `outstandingIssued()` ceiling) — not as two independently
tracked quantities. The doc's own wording ("Used: qty parts yang
dilepas/bekas DARI KENDARAAN") describes a materially different concept:
an old/removed part taken OFF the vehicle during a replacement, which has
no corresponding field anywhere in the schema (Consume-time only records
how much NEW product was installed, never how much old product was
removed). Resolving this properly would mean either (a) accepting the
existing architecture's "Used" = "issued stock returned in used-but-not-
defective condition" reading (a schema-free, zero-risk interpretation), or
(b) building a genuinely new "old part removed" tracking concept with its
own input point (most naturally at Consume time) — a real new feature, not
a popup redesign. **NEEDS_OWNER_DECISION**, recorded here rather than
guessed silently; nothing in Batches 9 or this partial Batch 10 depends on
resolving it, so it did not block the backend work below.

Backend changes made (code-complete, migrated, tested — this slice only):

- `work_order_part_returns.condition` gains `UNUSED_FAULTY` (doc: Return
  popup's Condition dropdown for a never-installed return offers "New
  Good"/"New Faulty", not just one option) via `ALTER TABLE ... DROP/ADD
  CONSTRAINT`, following the exact same pattern the pre-existing
  `disposition_status` widening migration used.
  `WorkOrderPartService::CONDITIONS` now
  `['UNUSED_NEW','UNUSED_FAULTY','USED_GOOD','USED_FAULTY']`;
  `UNUSED_FAULTY` behaves exactly like `USED_FAULTY` in `returnPart()`
  (`PENDING_INSPECTION`, never restocks immediately) and was added to
  `UsedPartDispositionController::index()`'s queue filter so it surfaces
  in the existing Used Sparepart Processing workflow rather than being
  silently invisible.
- New `work_order_part_return_evidence` table +
  `WorkOrderPartReturnEvidence` model + `WorkOrderPartReturnEvidenceService`
  — mirrors `VehicleDocumentService`/`VehicleDocumentController` exactly:
  private `local` disk (this is operational evidence, never publicly
  servable — per item 24 of the owner's image-upload scope, chosen by use
  not by copying VehicleBrand's public-disk pattern), UUID filename never
  the client's, JPG/PNG + 5MB validated server-side. Uploaded independently
  of the return itself (so a user can upload, preview, and individually
  remove images before confirming — doc: "Image yang berhasil diupload
  dapat di remove"), then linked to the `WorkOrderPartReturn` row
  `returnPart()` creates via a new optional `evidenceIds` parameter (only
  links rows still unlinked and belonging to the same planned part —
  can't hijack another part's evidence). The legacy single `evidence`
  string column is untouched for backward compatibility.
- New endpoints (all `permission:inventory.return`, all scoped to the
  owning Work Order + Planned Part, 404 otherwise):
  `GET/POST /work-orders/{wo}/planned-parts/{part}/return-evidence`,
  `GET/DELETE .../return-evidence/{evidence}`. Delete is rejected once an
  evidence row is linked to a confirmed return (immutable audit trail
  after the fact, matching the "three different operations" safety rule
  for image update — no new image supplied / replace / explicit remove
  are kept distinct).

Not yet done (the frontend half of Batch 10, paused before starting):
reusable `ImageUploadField` component (placeholder/preview/select/replace/
remove/JPG-PNG-validate/error-state), Consume popup (Install All checkbox
+ Installed Qty, opens only when outstanding issued qty > 1 per doc), and
the Return popup UI itself (system-info display of the outstanding
returnable quantity, Condition dropdown using the 4 values above with
their doc labels, Reason, and the new evidence upload wired to the
endpoints above instead of the current plain URL text input).

Tests: re-ran `InventoryReturnClassificationTest`(10),
`UsedPartDispositionTest`(10), `SparePartSaleTest`(8),
`WorkOrderExecutionTest`(6), `WorkOrderPartRequestTest`(12),
`WorkOrderClosureGuardTest`(3) — 49/49 PASS, no regressions (the new
`UNUSED_FAULTY` value and `evidenceIds` parameter are purely additive).
`pint --test` clean on every new/changed file. `php -l` clean on every new
file. MongoDB: NOT RUN, relocate-run-restore precedent followed, zero diff
confirmed before committing. Frontend: unchanged this slice (no frontend
file touched), not re-verified since nothing changed.

## Pause Checkpoint

Date/Checkpoint: 2026-09-23, mid-Batch-10, backend-only slice.
Branch: `claude/peaceful-rubin-sm50tx`.
Commit: see the commit immediately following this doc update in `git log`
(message starts `feat(work-order): add Return evidence upload backend`).

Current Batch: Batch 10 — Reusable image upload + Consume/Return popup
redesign (per "Improvement OptiFleet - Maintenance Request dan Work
Order").
Current Feature: Work Order Return Parts evidence upload + Consume popup
redesign.

Completed:
- Batches 1-9 (see their own Detail sections above) — fully done, tested,
  pushed.
- Batch 10, backend half only: `UNUSED_FAULTY` condition,
  `WorkOrderPartReturnEvidence` model/table/service, 4 new evidence
  endpoints, `returnPart()`'s new `evidenceIds` linking parameter,
  `UsedPartDispositionController` queue filter widened. See Batch 10
  Detail above for the full list.

Partially Completed:
- Batch 10 overall: backend infra is code-complete and tested; nothing in
  the frontend calls it yet, so it is inert (safe, non-breaking) until the
  frontend half lands.

Not Started:
- Batch 10's frontend half: reusable `ImageUploadField` component, Consume
  popup redesign, Return popup redesign.
- Batch 11 (Planned Parts vs Request Parts reconciliation), Batch 12
  (Product Inventory Configuration), Batch 13 (Consumable SDS upload),
  Batch 14 (OTHER item type investigation, Specification/Grade
  discriminator investigation, repository-wide image-URL sweep), Batch 15
  (final PR-readiness audit + report update).

Current In-Progress Point: nothing left mid-file — the backend slice above
was brought to a clean, tested, committed stopping point specifically so
the pause lands between files, not inside one.

Next Step: Resume Batch 10 by building the frontend `ImageUploadField`
component (placeholder/preview/select/replace/remove/JPG-PNG-validate),
then wire the Consume popup (Install All checkbox + Installed Qty,
gated on `outstandingIssued() > 1`) and the Return popup (system-info
display of the returnable quantity, Condition dropdown with the 4 values
this batch's backend now supports, Reason, and the evidence upload wired
to the `return-evidence` endpoints already built) into
`WorkOrderDetailPage.tsx`'s `PlannedPartsTab`. After that: `tsc`/`oxlint`/
`vite build`, a fresh backend regression pass, commit, push, then continue
to Batch 11.

Known Issues: none new. The pre-existing `WorkOrderService.php`/
`WorkOrder.php` Pint style debt (noted in the Batch 9 Detail) remains
untouched, out of scope.

Owner Decisions Needed:
1. The Consumable Specification/Grade conditional-mandatory trigger
   (carried over from the original Final Report, still unresolved).
2. **New, from this batch:** whether the Return popup's "Used Qty" should
   keep its current schema-free reading (issued stock returned in a
   used-but-not-defective condition, capped by the same outstanding-issued
   ceiling as "Unused Qty") or become a genuinely new "old part removed
   from the vehicle" concept requiring its own input point — see the
   Architecture note in the Batch 10 Detail above for the full reasoning.
   Not blocking: the frontend Consume/Return popup work can proceed under
   reading (a) and be revisited if the owner prefers (b).

Tests Executed: `InventoryReturnClassificationTest`,
`UsedPartDispositionTest`, `SparePartSaleTest`, `WorkOrderExecutionTest`,
`WorkOrderPartRequestTest`, `WorkOrderClosureGuardTest`, `pint --test`,
`php -l` on every new/changed file.
Tests Passed: 49/49 backend assertions across the 6 files above; pint and
php -l both clean.
Tests Failed: none.
Tests Skipped: none this slice (no frontend files changed, so `tsc`/
`oxlint`/`vite build` were not re-run — nothing to check).

MongoDB: NOT RUN — `ext-mongodb` unavailable in this sandbox, network-
installation blocked by org policy. Verified via the same relocate-run-
restore precedent as every prior batch; zero diff confirmed
(`git status --porcelain database/migrations/`) before committing.

Deployment Prerequisites: none new this slice (no new disk/env
requirement — reuses the already-configured `local` disk).

## Resume — Both Owner Decisions From the Pause Checkpoint Are Now Resolved

1. Consumable Specification/Grade trigger — **RESOLVED**, implemented and
   tested. See "OWNER DECISION 1 — RESOLVED" above (placed earlier in this
   document, next to where the item used to live under
   NEEDS_CONFIRMATION).
2. Return popup "Used Qty" — **RESOLVED** by the owner as a genuine old/
   removed-component-from-vehicle concept, distinct from an unused-issued-
   stock return, and explicitly must never reverse the newly-installed
   replacement part's consumption. This is a domain/inventory lifecycle
   change, not a UI label change — see "Batch 10 Detail (continued):
   Removed Component Return" below for the architecture impact analysis
   and implementation.

## Batch 10 Detail (continued): Removed Component Return — OWNER DECISION 2 RESOLVED

### Architecture impact assessment

**Current Return Model.** `WorkOrderPlannedPart` (a quantity-based,
warehouse-issued line item on a WO) → Reserve/Issue/Consume/Return via
`WorkOrderPartService`. `WorkOrderPartReturn` records every return with a
`condition` (as of this batch's earlier checkpoint:
UNUSED_NEW/UNUSED_FAULTY/USED_GOOD/USED_FAULTY) — but every one of these,
including the USED_* values, represents stock that originated from a
warehouse Issue and is flowing back, whether it was ever installed or
not. Confirmed by re-reading the existing (tested) test suite: a
USED_GOOD/USED_FAULTY return is exercised in `InventoryReturnClassification
Test` against a part that was **issued but never consumed** — i.e. the
existing "Used" condition already meant "physical condition of returned-
but-never-installed stock," not "a part that was on the vehicle."

**Current Inventory Movement.** `InventoryService` + `stock_movements`
ledger. `writeZeroEffectMovement()` is an established primitive (already
used for `CONSUME` and `SALE`) for an immutable, balance-neutral ledger
marker — exactly the posture an old-component return needs (must never
auto-restock).

**Separately existing:** `ComponentAsset`/`ComponentInstallation`/
`ComponentRemoval` already model a full serialized-asset install/remove/
replace/repair lifecycle (serial numbers, asset numbers, purchase cost) —
but for individually-serialized, high-value components, not routine
quantity-tracked spare parts. The Work Order doc's Return popup (Planned
Parts) is squarely quantity-based (Product + Qty), matching the lighter
`WorkOrderPlannedPart` domain, not this heavier one. Per the owner's
instruction to reuse serialized-asset tracking where it already exists
rather than inventing a parallel one: if a returned component's Product
has `track_serial_number = true`, the ComponentAsset module is the
architecturally correct home for it — wiring that integration is
explicitly out of scope for this batch (see REMAINING below) and was not
attempted without a dedicated design pass, to avoid a rushed integration
with an existing, working module.

**Gap.** No concept existed for "N units of Product X were removed FROM
THE VEHICLE (not from warehouse stock) during Job Y of WO Z, and returned
to the warehouse in [condition]" for non-serialized parts.

**Proposed Old-Component Model (implemented).** Two new tables, mirroring
the existing "Removal" / "Return" split ComponentAsset already uses for
the same real-world event, sized for ordinary quantity-tracked parts:
- `work_order_removed_components` — the removal event: `work_order_id`,
  `maintenance_job_id` (nullable), `replaced_by_planned_part_id`
  (nullable — traces to the new part installed in its place, when
  applicable), `product_id` (the old/removed product — independently
  selected, never auto-copied from the replacement, since the two are
  not guaranteed equal), `quantity`, `condition` (`GOOD`/`FAULTY` —
  reusing the vocabulary `work_order_part_returns.condition` already
  established for USED_GOOD/USED_FAULTY rather than inventing new
  terms), `notes`, `status` (`PENDING_RETURN`/`RETURNED`).
- `work_order_removed_component_returns` — the return-to-warehouse event:
  `warehouse_id`, `quantity`, `stock_movement_id`. Writes exactly one
  `stock_movements` row via a new `InventoryService::
  recordRemovedComponentReturn()` (mirrors `recordSale()`/
  `recordConsumption()` exactly) using a new `REMOVED_COMPONENT_RETURN`
  movement type — zero on-hand/available effect (same posture as
  CONSUME/SALE), so it can never accidentally become normal available
  stock.
- `work_order_removed_component_evidence` — mirrors
  `work_order_part_return_evidence` (private `local` disk, UUID
  filenames, JPG/PNG + 5MB validated) but simpler: a Removed Component
  has exactly one lifecycle and exactly one return, so evidence tied to
  `work_order_removed_component_id` is unambiguous from upload time —
  no separate "link to a specific return" step is needed (unlike Planned
  Part evidence, where one part can be returned in several batches over
  time).

**Relationship to the New Installed Part.** `replaced_by_planned_part_id`
(nullable FK to `work_order_planned_parts`) — nullable because a removal
is not always a like-for-like replacement (a straight decommission has no
"this replaced it" part) and the doc gives no mechanism to force that
link.

**Inventory Effect.** Zero. Removal itself creates no ledger entry (no
warehouse-side event yet — it only leaves the vehicle). The Return
creates exactly one zero-balance-effect `StockMovement`
(`REMOVED_COMPONENT_RETURN`); `quantity_on_hand` is never touched.
Verified by test: after a removed component is returned,
`warehouse_stocks.quantity_on_hand` for that product is `0` (the ledger-
anchor row `writeZeroEffectMovement()`'s `lockOrCreateStock()` always
creates exists, but its balance never moves) — the same posture already
proven correct for CONSUME/SALE.

**New Part Consumption Reversed by Old-Part Return: NO** — confirmed by
test (`test_removing_and_returning_an_old_component_does_not_reverse_the
_new_parts_consumption`): the replacement Planned Part's `consumed_quantity`
and `status=CONSUMED` are asserted unchanged after the old component's
full removal + return cycle. The two are entirely separate write paths
with no shared mutation.

**Database Impact.** 3 new tables (additive, tenant-scoped, FK-indexed),
`stock_movements.movement_type` CHECK constraint widened by one value
(`REMOVED_COMPONENT_RETURN`), following the exact precedent the existing
`CONSUME`/`SALE` widenings already used. No existing table altered
destructively; no existing data touched.

**API Impact.** New endpoints under `/work-orders/{wo}/removed-
components` (list, create, delete-while-pending), `.../return`, and
`.../evidence` (upload/list/show/delete) — `permission:maintenance_job.
manage` for the removal record itself (recording what was done is akin
to logging work), `permission:inventory.return` for the actual return-
to-warehouse step (same permission the existing Unused Return uses,
since it's the warehouse-facing action). The existing `/planned-parts/
.../return` endpoint is unchanged in meaning — still purely the unused-
issued-stock return it always was; only its Condition vocabulary/upload
mechanism changed (see the "backend half" Detail above).

**Frontend Impact.** New `RemovedComponentsSection` inside the Planned
Parts tab, below the existing (per-part) Unused Return UI — presented as
a clearly distinct section (not a merged/ambiguous quantity field) so
the two concepts are never conflated, satisfying the doc's own
conceptual popup split (Unused Part vs Old/Removed Component) without
literally forcing a WO-level concept into a single-planned-part-scoped
dialog, since `replaced_by_planned_part_id` is optional or (item 18's
"two clearly different concepts" combined with this domain's real
cardinality — a removal isn't always 1:1 with one planned part row).

**History/Audit Impact.** Both new models `use Auditable`, so removal and
return events automatically surface in the existing generic Audit tab
(`audit_logs`, `resource_type` = the new model classes) — no bespoke
event log needed, reusing the same mechanism `WorkOrderPartReturn`
already relies on.

**Costing Impact.** Per the owner's explicit instruction not to invent a
financial rule: the removed component's return records quantity/
traceability only, with zero cost/valuation effect — no accounting
credit, no reversal of the replacement part's cost. **Flagged
separately, not blocking:** whether a returned old component should ever
carry a value onto the books (e.g. if later sold/repaired/scrapped with
salvage value) is genuinely undefined by the requirement documents and
is a new NEEDS_OWNER_DECISION, distinct from the "Used Qty" definition
itself.

**Migration Risk.** Low — purely additive; the only existing-file changes
are a `WorkOrder::removedComponents()` relation, an `InventoryService`
method addition, and `WorkOrderController::show()`'s eager-load list
(all additive, none touch existing behavior). Full regression (70 tests
across `WorkOrderRemovedComponentTest`, `InventoryReturnClassification
Test`, `UsedPartDispositionTest`, `SparePartSaleTest`,
`WorkOrderExecutionTest`, `WorkOrderPartRequestTest`,
`WorkOrderClosureGuardTest`, `WorkOrderTest`) confirms zero regressions.

### Implementation

- `WorkOrderRemovedComponent`/`WorkOrderRemovedComponentReturn`/
  `WorkOrderRemovedComponentEvidence` models (`App\Domain\WorkOrder\Models`).
- `WorkOrderRemovedComponentService`: `remove()` (gated by the same
  `assertExecutable()` as the rest of the active-execution part
  lifecycle — Draft/External excluded), `delete()` (only while
  `PENDING_RETURN` — a `RETURNED` record is an immutable inventory
  transaction, per the owner's own instruction not to add Edit merely
  for UI consistency), `returnToWarehouse()` (row-locked, rejects a
  second return, writes the zero-effect movement), `uploadEvidence()`/
  `deleteEvidence()` (JPG/PNG + 5MB, delete rejected once the component
  is `RETURNED`).
- `InventoryService::recordRemovedComponentReturn()` — new, mirrors
  `recordSale()` exactly.
- `WorkOrderExecutionController`: 8 new actions (list/create/delete
  removal, return, evidence upload/list/show/delete), added to
  `routes/api/app.php` under the existing `work-orders/{workOrder}/...`
  group.
- Frontend: `ImageUploadField` (new, `src/components/`) — the reusable
  "Image Placeholder → JPG/PNG Upload → Preview" control the owner's
  image-upload scope requires repository-wide; presentational only
  (upload/remove delegated to the caller), used by both the Unused
  Return evidence (backend half of this batch) and the new
  `RemovedComponentsSection`. `PlannedPartsTab` rewritten: the Consume
  action now opens a Modal with Install All / Installed Qty when
  outstanding issued qty > 1 (doc: a single unit has nothing to choose,
  consumed directly with no dialog); the Return form's Condition
  dropdown now shows the doc's 4 labels (New Good/New Faulty/Used Good/
  Used Faulty) and displays "Available to return" as read-only
  SYSTEM_INFORMATION instead of asking the user to re-derive it; the
  Evidence URL text field is replaced by `ImageUploadField` wired to the
  new upload endpoints. New `RemovedComponentsSection` component: record
  a removal (Product/Job/Replaces-which-planned-part/Qty/Condition/
  Notes — Job and replacement-part are optional CONDITIONAL_INPUT,
  Product/Qty/Condition are USER_INPUT), per-component evidence upload,
  and a "Return to Warehouse" action (Warehouse + Reason) once ready.

### Tests

11 new (`WorkOrderRemovedComponentTest`): new-part-remains-consumed
(the core domain invariant), double-return rejected, old product can
differ from the new one, removal without a replacement part is allowed,
invalid condition rejected, delete allowed only while pending, removal
rejected while WO not executable, evidence upload accepts JPG/PNG and
rejects other MIME types, evidence delete rejected once returned, tenant
isolation, permission enforcement. Full regression: 70/70 PASS across 8
files (listed above under Migration Risk), no regressions. `pint --test`
clean on every new/changed backend file (one file's Rule::exists inline-
qualification was auto-fixed via `pint` itself, matching existing repo
style — verified via `git stash` that the fixer flags were newly
introduced by this batch's own code, not pre-existing debt, then fixed
before committing). Frontend: `tsc --noEmit` clean, `oxlint` clean (no
new warnings in any touched file), `vite build` succeeds.

MongoDB: NOT RUN, relocate-run-restore precedent followed for every test
run in this continuation, zero diff confirmed before every commit.

### REMAINING from this decision (disclosed, not blocking)

- Serialized-component integration: a removed component whose Product
  has `track_serial_number = true` currently goes through the same
  quantity-based flow as any other — it does NOT yet integrate with the
  existing `ComponentAsset`/`ComponentRemoval` serialized-asset module.
  Per the owner's own "reuse it if available, do not invent parallel
  serial-number tracking" instruction, the correct long-term direction
  is wiring THAT module in for serialized products, not building a
  second serial-tracking mechanism here — this needs its own design
  pass (how does a WO Return popup select a *specific* ComponentAsset
  instance to remove?) and was intentionally not rushed into this batch.
- Costing/valuation treatment of a returned old component (see Costing
  Impact above) — NEEDS_OWNER_DECISION, not answered by the requirement
  documents.
- Full disposition workflow (inspect → propose → decide, mirroring
  `UsedPartDispositionService`) for removed components is NOT built —
  the return only records the movement. Per the owner's own "do not
  over-engineer... do not automatically build a complete remanufacturing
  /refurbishment/disposal system" instruction, this is deliberately
  deferred as a natural, valuable follow-up rather than attempted now.

## Batch 11 Detail: Planned Parts vs Request Parts reconciliation

### Scope

The requirement doc's Work Order tab list distinguishes two things that
had collapsed into a single tab in the existing implementation. Precise
grep of the doc's status-section headers and tab-list occurrences
confirmed:

- **"Request Parts"** is the pre-existing rich tab (issue/reserve/
  consume/return against warehouse stock, the doc's own words:
  "sebelumnya adalah Tab Planned Parts yang berubah nama") — it is
  visible only from `IN_PROGRESS` onward, not from Draft.
- **"Planned Parts"** (the doc's true meaning) is a NEW, thin,
  budgeting-only tab: Product + Qty (+ optional Notes) per line, no
  warehouse interaction, no stock effect, feeding only "Estimated Parts
  Cost" — usable through the whole planning window including Draft,
  unlike Reserve/Issue/Consume/Return which require the WO to be
  executable.

These were architecturally conflated before this batch (one tab, one
model, doing both jobs). This batch separates them into two genuinely
distinct domains rather than repurposing one for both meanings, the
same discipline applied to Batch 10's Removed Component Return.

### Implementation

- New `work_order_planned_part_estimates` table (migration
  `2026_09_28_000004`): `tenant_id`, `work_order_id`, `product_id`,
  `quantity` (decimal 16,4), `notes` (nullable), `created_by`,
  timestamps — no warehouse/status/condition columns, since this domain
  never touches stock.
- New `WorkOrderPlannedPartEstimate` model (`BelongsToTenant`,
  `Auditable`, `HasUuids`) — `ALLOWED_PRODUCT_TYPES = ['SPARE_PART',
  'TIRE', 'CONSUMABLE']`, matching the existing Request Parts / Product
  domain restriction (Tools and Other item types are excluded).
- New `WorkOrderPlannedPartEstimateService` — add/delete, gated by the
  existing `assertPlanningEditable()` gate (the broader Draft-through-
  REWORK window used for planning-stage actions), not
  `assertExecutable()` — confirming the doc's "no warehouse interaction,
  usable from Draft" requirement was correctly mapped to the existing
  three-gate system rather than inventing a fourth.
- `WorkOrder::computedEstimatedPartsCost()` — Σ(quantity × the
  product's `average_unit_cost` from any one `WarehouseStock` row), the
  same documented-limitation posture as Batch 9's
  `computedEstimatedLaborCost()`: no dedicated Product list-price field
  exists anywhere in the schema, so `average_unit_cost` is used as the
  closest existing analogue to "harga product"; a product with no stock
  record anywhere yet contributes nothing rather than blocking the
  total. Never overwrites the legacy manually-entered
  `estimated_parts_cost` column.
- `WorkOrderController::show()` — eager-loads `plannedPartEstimates.product`
  and appends `estimated_parts_cost_computed` (single-record computed
  value only, same non-N+1 discipline as the other computed fields).
- `WorkOrderExecutionController` — `addPlannedPartEstimate()` /
  `destroyPlannedPartEstimate()`; routes: `POST
  /work-orders/{workOrder}/planned-part-estimates`, `DELETE
  .../{plannedPartEstimate}`.
- Frontend (`WorkOrderDetailPage.tsx`): `INTERNAL_TABS` gained
  `'Request Parts'` alongside the existing `'Planned Parts'`; new
  `REQUEST_PARTS_VISIBLE_STATUSES` list gates `'Request Parts'` to
  `IN_PROGRESS/ON_HOLD/WAITING_PART/QC_PENDING/REWORK/COMPLETED/CLOSED/
  REJECTED/CANCELLED` (first per-tab conditional-visibility instance —
  previously only a binary Internal/External split existed; the full
  per-status tab/button visibility matrix remains explicitly
  out-of-scope future work). The old rich tab component was renamed
  `RequestPartsTab` (body unchanged, heading text updated). New
  `PlannedPartsEstimatesTab` component: Product dropdown filtered
  client-side to Sparepart/Tire/Consumable, Qty, Notes, Add/Delete, and
  a read-only "Estimated Parts Cost" field reading
  `estimated_parts_cost_computed` as SYSTEM_DERIVED information (never
  asks the user to compute or re-enter it). `OverviewTab`'s Parts Cost
  now reads the same computed field.
- `types/index.ts` — `WorkOrderPlannedPartEstimateItem` interface;
  `planned_part_estimates?`/`estimated_parts_cost_computed?` added to
  `WorkOrderItem`.

### Tests

4 new (`WorkOrderPlannedPartEstimateTest`): add/delete round-trip with
correct computed-cost arithmetic and no stock-table interaction,
product-type restriction (Tool rejected), allowed while WO is still
DRAFT (proving the planning-gate mapping, in contrast to Request
Parts' executable-only gate), permission enforcement. Focused run:
4/4 PASS (12 assertions), executed twice in this session (once before
the environment incident below, once after DB cleanup), both times
green. `pint --test` clean on every new/changed backend file (one new
test file's inline `\App\Domain\Identity\Models\Tenant::query()`
reference triggered the same import-qualification fixers as Batch 10 —
verified via `git stash` as newly introduced by this file, then
auto-fixed via `pint` itself into a proper `use` import). Frontend:
`tsc --noEmit` clean, `oxlint` no new warnings on the two touched
files.

**Full-suite regression: NOT RUN to completion this session.** Three
attempts at `php artisan test` (full suite) hung indefinitely at the
Unit->Feature test boundary. Root-caused to an ENVIRONMENT_FAILURE, not
a code regression: an earlier killed `php artisan test` process left an
orphaned PHPUnit child and an "idle in transaction" Postgres connection
holding a lock on the `permissions` table (`pid ... DEALLOCATE
pdo_stmt_...`), which then permanently blocked every subsequent test
run's own permission-seeding insert (`insert into "permissions" ...`
stuck on `wait_event=transactionid`). Confirmed via
`pg_stat_activity` and resolved via `pg_terminate_backend` + killing
orphaned `phpunit` processes; the DB is now clean (verified: only the
inspecting session's own connection remains). This was not re-attempted
a fourth time in favor of honoring "do not start a broad new
investigation" during this pause — full-suite regression is deferred
to the next work session as the documented Next Exact Step. `vite
build` also NOT RUN this session (time/priority given to the DB
incident); `tsc --noEmit` and `oxlint` (both clean, see above) are the
executed frontend evidence for this batch.

MongoDB: NOT RUN (ext-mongodb unavailable, network-blocked from
installing); relocate-run-restore precedent followed for the one
focused run that needed it, zero diff on `database/migrations/`
confirmed via `git status --porcelain` before committing this
checkpoint.

### REMAINING from this batch (disclosed, not blocking)

- The full per-status Tab/button visibility matrix beyond
  `REQUEST_PARTS_VISIBLE_STATUSES` is not built — flagged since Batch 9
  as a large, distinct body of work outside this batch's priority list.

## Pause Checkpoint (Latest — Batch 11)

Checkpoint Date: 2026-09-23

Branch: `claude/peaceful-rubin-sm50tx`

Previous Checkpoint: commit `b4505d8` (the Batch 10 "Removed Component
Return — OWNER DECISION 2 RESOLVED" commit; the pause checkpoint before
that, at commit `2032815`, was already resolved by the "Resume — Both
Owner Decisions..." section above and is superseded).

New Checkpoint Commit: recorded below, after this section is committed
(see the commit this paragraph ships in).

Current Batch: Batch 11 — Planned Parts vs Request Parts reconciliation.

Current Feature: complete and tested at the unit/focused level; full-
suite regression not executed to completion this session (see Known
Issues).

Last Completed Step: Batch 11 implementation (backend + frontend),
focused test file (4/4 PASS, run twice), `pint --test` clean on all
new/changed backend files, `tsc --noEmit` clean, `oxlint` clean on the
two touched frontend files, Mongo migrations restored with verified
zero diff.

Current Partial Step: none — Batch 11's own code is complete, not
partial. What is incomplete is *verification breadth* (full-suite
regression and frontend `vite build` were not run this session), not
the feature itself.

Next Exact Step: Before starting Batch 12 (Product Inventory
Configuration), run one clean full-suite regression to establish a
known-good baseline for everything merged so far (`git log --oneline`
through this checkpoint commit): `cd backend && php artisan test`,
watched to completion (do not let it run unattended past ~3-4 minutes
without checking `ps` + `pg_stat_activity` for the orphaned-process /
lock pattern described below). If it hangs again, apply the same fix
(`pkill -9 -f phpunit`, then `pg_terminate_backend` every
`pg_stat_activity` row for `optifleet_test` that is not the inspecting
session) before retrying — do not retry blindly more than once without
that cleanup. Once a full-suite PASS is captured, run `cd frontend &&
npx vite build` (not yet run this session) to confirm no production
build regression, then proceed to Batch 12.

### Completed Since Previous Checkpoint

- Batch 11: `work_order_planned_part_estimates` table/model/service,
  `computedEstimatedPartsCost()`, 2 new API endpoints, frontend tab
  split (`Planned Parts` budgeting-only vs `Request Parts` renamed-old-
  tab with `REQUEST_PARTS_VISIBLE_STATUSES` gating), 4 new tests — see
  "Batch 11 Detail" above for full description.
- Status doc's Batch 11 Detail section written and, in this pause pass,
  corrected to not overstate an untested full-regression result (see
  Known Issues).

### Partially Completed

- None from Batch 11 itself. The only "partial" item is verification
  breadth (full-suite regression, `vite build`) — see Next Exact Step.

### Not Started

- Batch 12 (Product Inventory Configuration), Batch 13 (Consumable SDS
  upload), Batch 14 (OTHER item type investigation + repository-wide
  Image URL sweep), Batch 15 (final PR-readiness audit). Unchanged from
  before this checkpoint.

### Owner Decisions Resolved

- Consumable Specification/Grade → Category/Subcategory (Batch prior to
  this checkpoint; unaffected by this pause).
- Return Used Qty → Old/Removed Component from Vehicle, distinct from
  Unused Return, does NOT reverse the new installed part's consumption
  (Batch prior to this checkpoint; unaffected by this pause). Restated
  for clarity:
  - **UNUSED RETURN** = warehouse-issued inventory that was not
    consumed and is returned to Warehouse (existing
    `WorkOrderPlannedPart`/`WorkOrderPartReturn` flow, unchanged).
  - **OLD / REMOVED COMPONENT RETURN** = a component previously
    installed on the Vehicle that is removed during
    maintenance/replacement and returned from Vehicle to Warehouse
    (`WorkOrderRemovedComponent` / `WorkOrderRemovedComponentReturn`,
    new in Batch 10).
  - **OLD COMPONENT RETURN != reverse consumption of the newly
    installed replacement part.** Proven by
    `WorkOrderRemovedComponentTest::test_removing_and_returning_an_old_component_does_not_reverse_the_new_parts_consumption`.

### Owner Decisions Still Needed

- None new from this checkpoint. Pre-existing, still open (disclosed
  earlier, not raised by this pause): costing/valuation treatment of a
  returned old component (see Batch 10 Detail's Costing Impact); and
  the follow-on question of whether/how to integrate serialized
  `ComponentAsset` tracking into the Removed Component Return flow.

### Known Issues

- **ENVIRONMENT_FAILURE, ROOT-CAUSED AND RESOLVED (not a code defect):**
  during this pause's verification step, `php artisan test` (full
  suite) hung three consecutive times at the Unit->Feature boundary.
  Cause: a full-suite run started earlier in this session was
  interrupted with `kill -9` on its wrapper `bash`/`php artisan test`
  processes, but the actual `vendor/phpunit/phpunit/phpunit` child
  process(es) were NOT killed and kept running orphaned, holding a
  Postgres connection to `optifleet_test` open in `idle in transaction`
  state (stuck on `DEALLOCATE pdo_stmt_...`) that blocked every later
  run's own `insert into "permissions"` seeding statement
  (`wait_event_type=Lock, wait_event=transactionid`), because a fresh
  test run's `php artisan test` command re-attempted the SAME database
  while the orphan(s) were still alive across multiple retries.
  Diagnosed via `pg_stat_activity` (state, wait_event, query, duration)
  and `ps aux` correlation. Resolved via `pkill -9 -f phpunit` +
  `pg_terminate_backend()` for every non-self connection to
  `optifleet_test`; confirmed clean (`SELECT count(*) FROM
  pg_stat_activity WHERE datname='optifleet_test'` = 1, the inspecting
  session itself). Full-suite regression was deliberately NOT re-
  attempted a fourth time in this pause (out of scope for "minimum
  stabilization only" during a pause) — see Next Exact Step.
  **Operational lesson for future sessions:** always `pkill -9 -f
  phpunit` (not just the wrapper `bash`/`php artisan test` PIDs) and
  verify `pg_stat_activity` is clean before retrying a hung
  `php artisan test` run.
- The Batch 11 status doc entry originally (before this pause pass)
  claimed "Full regression: 52/52 PASS" — this was NOT actually
  executed in this session (the only executed backend evidence was the
  4/4 focused `WorkOrderPlannedPartEstimateTest` run plus `pint`).
  Corrected in this checkpoint per the "never fabricate test results"
  rule; see the corrected Tests section above.

### Database Changes

- `2026_09_28_000004_create_work_order_planned_part_estimates_table.php`
  (new, this checkpoint's own batch) — reviewed in this pause: table is
  intentional, matches the approved "Planned Parts" concept, tenant_id/
  work_order_id/product_id FKs correct (tenant + work_order cascade on
  delete, product restrict on delete — a Product must not be deletable
  out from under a budgeting line), composite `(tenant_id,
  work_order_id)` index present, `down()` valid
  (`dropIfExists`), no temporary or duplicate migration found. No other
  migrations changed since the previous checkpoint.

### API Changes

- `POST /api/v1/app/work-orders/{workOrder}/planned-part-estimates`
  and `DELETE .../{plannedPartEstimate}` (new, Batch 11). No other API
  surface changed since the previous checkpoint.

### Frontend Changes

- `WorkOrderDetailPage.tsx`: `Request Parts` tab added alongside
  `Planned Parts`, gated by `REQUEST_PARTS_VISIBLE_STATUSES`; new
  `PlannedPartsEstimatesTab` component. `types/index.ts`: new
  `WorkOrderPlannedPartEstimateItem` interface. No other frontend files
  changed since the previous checkpoint.

### Image Upload Changes

- None since the previous checkpoint (Batch 11 has no image-upload
  surface — the "Planned Parts" budgeting tab is Product/Qty/Notes
  only). The `ImageUploadField` component and its Return/Removed-
  Component-evidence usage remain as delivered in Batch 10, unchanged.

### Popup Changes

- None since the previous checkpoint. Batch 11 added a tab (not a
  popup/modal); its Add/Delete row form is a simple inline row, not a
  dialog — Product (`USER_INPUT`), Qty (`USER_INPUT`), Notes
  (`USER_INPUT`, optional) are its only fields, no fields that
  duplicate system-known information.

### Tests Executed

- `php artisan test --filter=WorkOrderPlannedPartEstimateTest` (run
  twice, both green).
- `./vendor/bin/pint --test` on all Batch 11 new/changed backend files.
- `npx tsc --noEmit` (frontend, full project).
- `npx oxlint` on the two Batch 11-touched frontend files.
- Three attempts at the full-suite `php artisan test` (see Known
  Issues) — none reached completion; not counted as executed
  validation for any individual test within them.

### Tests Passed

- `WorkOrderPlannedPartEstimateTest`: 4/4 (12 assertions), both runs.
- Pint: clean (0 files with fixer flags among the files checked).
- `tsc --noEmit`: 0 errors.
- `oxlint`: 0 warnings on the checked files.

### Tests Failed

- None. (The full-suite hangs were process/DB-lock hangs, not test
  failures — no test in them reached a fail or pass state.)

### Tests Skipped

- Full-suite regression: not completed, see Known Issues and Next Exact
  Step — to be run at the start of the next session before Batch 12.
- `vite build`: not run this session.

### MongoDB

- SKIPPED / NOT RUN (ext-mongodb unavailable, network-blocked from
  installing in this sandbox). Migration files were temporarily
  relocated to `/tmp/mongo-migrations-holding/` for the one focused
  test run that needed it, then restored; `git status --porcelain` on
  `backend/database/migrations/` is clean (no diff) as of this
  checkpoint commit.

### Deployment Prerequisites

- Unchanged from prior checkpoints: `ext-mongodb` must be installed in
  any environment that runs the Mongo-backed Analytics/Intelligence
  migrations and tests (SKIPPED/NOT RUN in this sandbox throughout the
  session). No new deployment prerequisite introduced by Batch 11.

## Resume From Checkpoint `435f4b7`: Full Backend Regression + Frontend Build (baseline established)

### What this section is

The Next Exact Step recorded in the pause checkpoint above was: run one
clean, watched full-suite backend regression, then a frontend
production build, before starting Batch 12. This section records the
actual, executed results — not a repeat of Batch 11's own
implementation (unchanged, see above).

### Environment: Postgres was not running

This sandbox session started fresh; `service postgresql start` was
required before any test could run (`SELECT 1` failed with "connection
refused" beforehand). This is routine sandbox startup, not a defect.

### Confirming and root-causing the deadlock from the previous checkpoint

The full suite was run watched, per the Next Exact Step. It reproduced
the same hang pattern recorded in the pause checkpoint — but this time
on a single, freshly-started `php artisan test` process (no orphaned
process from a prior `kill -9` was involved, ruling out that theory).
`pg_stat_activity` showed the identical signature: one connection
`idle in transaction` (stuck on `DEALLOCATE pdo_stmt_...`), a second
connection blocked (`wait_event=transactionid`) on
`insert into "permissions" ...`. Both connections belonged to the same
`phpunit` PID.

**Isolation test:** the full suite was re-run with
`tests/Feature/Analytics/` and `tests/Feature/Intelligence/`
temporarily relocated (same technique as the Mongo migrations — these
two directories reference the Mongo-backed Analytics/Intelligence
domain and were the prime suspect, per the architecture doc's own
"MongoDB is an analytical projection only" framing). With them
excluded, the full suite ran to completion cleanly — no hang. This
narrows the deadlock to something in/around the Analytics or
Intelligence Feature tests interacting badly with `ext-mongodb`'s
absence (most likely a service provider or lazy-connection path that
behaves differently — hangs instead of throwing — when the driver
class is missing versus when the migrations are simply absent).
**Root-causing the exact mechanism inside Analytics/Intelligence was
NOT pursued further** — those tests are already an established,
disclosed MongoDB dependency (SKIPPED/NOT RUN throughout this session
for the same underlying reason: `ext-mongodb` unavailable, network-
blocked from installing), so this is classified **MONGODB_DEPENDENCY**,
not a new defect, and pursuing the exact hang-vs-throw mechanism inside
those tests would be a disproportionate detour for an already out-of-
scope area. Both directories were restored byte-for-byte afterward
(`git status --porcelain` confirms zero diff on
`tests/Feature/Analytics/`, `tests/Feature/Intelligence/`, and
`database/migrations/`).

### A real, pre-existing defect found and fixed

With Analytics/Intelligence excluded, the full suite completed with
**17 failures**, all sharing one identical error:

```
WorkOrderException: Findings and Diagnosis can only be added or
removed while status is Draft (currently IN_PROGRESS).
at app/Domain/WorkOrder/Services/WorkOrderExecutionService.php:214
```

**Root cause:** `OperationsSeeder.php` and `FunctionalTestWorkOrderSeeder.php`
(4 call sites total) call `WorkOrderExecutionService::addFinding()`
**after** calling `WorkOrderService::start()`. This directly violates
the Draft-only Finding/Diagnosis scoping rule established by the
Finding-422 remediation (commit `6cae9fa`, `FINDING_SCOPE_STATUSES =
['DRAFT']`) — a rule that predates this entire continuation (Batches
8-11) and was never violated by any code this session touched
(`git diff` from the Batch 8 checkpoint through Batch 11 shows zero
changes to either seeder file). `git log` confirms both seeders were
last aligned (`03bcdd9`, `8cefb2b`) **before** the Finding-422 fix
(`6cae9fa`) was written, and neither was ever updated afterward to
respect the new Draft-only rule. **Classification:
PRE_EXISTING_FAILURE** — not a regression from Batch 11 or from any
batch completed in this continuation.

All 17 failing tests were 3 seeder-integration test classes
(`OperationsSeederTest`, `SupplyChainSeederTest` — which itself seeds
`OperationsSeeder` — and `FunctionalTestingSeederTest`) whose `setUp()`
runs the affected seeder; since a `setUp()` exception fails every test
method in the class, 1 root cause produced all 17 failures (1 in
`OperationsSeederTest`, 7 cascaded through `SupplyChainSeederTest`, 8
cascaded through `FunctionalTestingSeederTest` via
`FunctionalTestWorkOrderSeeder`). `FunctionalTestExternalWorkOrderSeeder.php`'s
own `addFinding()` calls were verified NOT affected — they call
`ExternalWorkOrderService::addFinding()`, a distinct method with its
own status rule for the External Work Order flow, unrelated to
`WorkOrderExecutionService::assertFindingScopeEditable()`.

**Fix (smallest correct change, no business rule altered):** reordered
each of the 4 call sites so `addFinding()`/`addDiagnosis()` run
immediately after `WorkOrder::create()`/`fromMaintenanceRequest()`
(while status is still DRAFT), before `submit()`/`approve()`/.../`start()`
move the WO past Draft — i.e. the seeders were corrected to match the
already-approved business rule, the rule itself was not touched.
`addJob()` (gated by the broader `assertPlanningEditable()` window) and
`resolveFinding()` (ungated) were left exactly where they were —
verified via reading `WorkOrderExecutionService.php` that only
`addFinding`/`addDiagnosis` use `assertFindingScopeEditable()`.

- `database/seeders/OperationsSeeder.php`: 1 call site.
- `database/seeders/FunctionalTestWorkOrderSeeder.php`: 3 call sites
  (`FT-WO-IN_PROGRESS`, `FT-WO-ON_HOLD`, `FT-WO-COMPLETED` scenarios).

`pint --test` on both files: pre-existing fixer flags only, confirmed
via `git stash` comparison (identical flags before and after this
fix's own edits — no new style debt introduced).

### Verification

- Focused re-run of the 3 previously-failing test classes:
  `OperationsSeederTest` 1/1 PASS, `FunctionalTestingSeederTest` 8/8
  PASS, `SupplyChainSeederTest` 8/8 PASS.
- **Full non-Mongo backend regression (clean, watched, to completion):
  779/779 PASS, 3525 assertions, 0 failures.** This is the actual,
  executed full-suite baseline for everything merged through this
  checkpoint (Batches 1-11 inclusive), including
  `WorkOrderPlannedPartEstimateTest` (4/4), `WorkOrderRemovedComponentTest`
  (11/11), and every other Feature/Unit test file outside
  Analytics/Intelligence.
- MongoDB (Analytics/Intelligence): SKIPPED / NOT RUN — `ext-mongodb`
  unavailable, network-blocked from installing; this session's hang
  investigation (above) further confirms excluding them is necessary,
  not merely a convenience.
- Frontend: `cd frontend && npm run build` (the repository's canonical
  `tsc -b && vite build` script) — **PASS**, built in 2.25s (one
  pre-existing advisory about a >500kB chunk, not an error, unrelated
  to this session's changes). `npm run lint` (`oxlint`, full project) —
  0 errors; the ~25 warnings printed are all pre-existing, in files
  never touched by this continuation (e.g. `VehicleListPage.tsx`,
  `AuthContext.tsx`, `MaintenancePackagesPage.tsx`) — none in
  `WorkOrderDetailPage.tsx` or `types/index.ts`.

### Checkpoint gate status

```text
Backend broad non-Mongo regression  -> PASS (779/779, 0 failures)
Frontend type check                 -> PASS (tsc -b, part of build)
Frontend lint                       -> PASS (0 errors; pre-existing warnings only)
Frontend production build           -> PASS
MongoDB (Analytics/Intelligence)    -> SKIPPED / NOT RUN (documented dependency)
Working tree                        -> controlled (git status --porcelain
                                        clean except the 2 seeder files
                                        this fix touches)
Known blocker                       -> NONE
```

Gate satisfied. Proceeding to Batch 12 (Product Inventory
Configuration).

## Batch 12 Detail (Product Inventory Configuration)

### Source document

The "Inventory Configuration" requirement is **not** in
`Next_Improvement_Tenant_Portal_-_Products` (the doc used for Batches
4-5's per-Item-Type Specification work) — that doc's Product section
ends with an explicit principle (`## Prinsip pemisahan data`) listing
exactly what must stay OFF the Item Master form (Current Stock among
them). The Inventory Configuration table is a distinct, later section
found only in the sibling document `Next_Improvement_Tenant_Portal`
(no "-Products" suffix), added after the per-Item-Type Specification
sections, introduced by: *"Saya justru akan menambahkan satu section
Inventory Configuration setelah Type Specification. Field ini dapat
digunakan lintas Item Type tetapi perilakunya berbeda."* Both source
`.txt` extractions were re-read directly for this batch (not
summarized from a prior Final Report), per the re-audit discipline.

### Requirement matrix (from the source table, cross-referenced against the repository)

| Field | Doc: Sparepart/Consumable/Rim/Tire/Tool/Equipment | Domain owner | Existing implementation | Batch 12 action |
|---|---|---|---|---|
| Stock Tracking | ✓ all | INVENTORY_LEVEL (implicit) | Every Product gets a `WarehouseStock` row via any `InventoryService` operation, universally — not a per-product toggle to configure, a system fact | None — already true by construction |
| Minimum Stock | O all | INVENTORY_LEVEL (`WarehouseStock`) | Migration/model/`WarehouseStockController::updateThresholds()`/route/permission all already existed (backend-complete); **zero frontend UI ever called it** | **Built**: `ThresholdsModal` in `WarehouseStockListPage.tsx` |
| Reorder Point | O all | INVENTORY_LEVEL (`WarehouseStock`) | Same as above; also already consumed by `WarehouseStock::reorderStatus()`, `DashboardController`'s low-stock query, `InventoryMetricsExtractor` (Analytics), `InventoryDemandForecastService` (Intelligence), and `PurchaseRequest`'s `REORDER_POINT` source type | **Built**: same modal |
| Maximum Stock | O all | INVENTORY_LEVEL (`WarehouseStock`) | Same as above | **Built**: same modal |
| Serialized | Sparepart optional/Consumable No*/Rim recommended/Tire Yes(system)/Tool recommended/Equipment Yes | PRODUCT_MASTER_LEVEL | `Product.track_serial_number` (universal boolean, already on the base Product model, not per-spec-table) — already wired end-to-end in `CreateProductModal`/`EditProductModal` via `needsField('track_serial_number', itemType)`, gated exactly to `['SPARE_PART','RIM','TOOL','EQUIPMENT']` (Consumable excluded per "No*", Tire excluded because it is system-controlled, not user-set, matching the doc's "YES (System Controlled)") | None — already correct |
| Batch Tracked | Consumable optional, others No | PRODUCT_MASTER_LEVEL | `Product.track_batch` (universal boolean) — wired in Create/Edit, applied only when `itemType === 'CONSUMABLE'` | None — already correct |
| Expiry Tracked | Consumable optional, others No | ITEM_TYPE_DEFAULT (`ProductConsumableSpec.track_expiry`) | Already implemented (Consumable-only spec field), wired in Create/Edit | None — already correct |
| Installable | Sparepart "Usually Yes"/Rim/Tire Yes/others No | N/A | Descriptive-only row explaining why the item type distinction exists (whether the item physically mounts on a vehicle) — not phrased as a Requirement/Input-Type field anywhere in either source doc, no Create-form row proposed for it | **NOT_APPLICABLE** — informational commentary, not a configurable field; confirmed by its absence from every per-Item-Type Specification table in both source docs |
| Checkout-able | Tool Yes, others No | ITEM_TYPE_DEFAULT (`ProductToolSpec.checkout_required`) | Already implemented (Tool-only), wired in Create/Edit | None — already correct |
| Maintainable | Tool/Equipment "Optional", Rim/Tire "Optional", others No | ITEM_TYPE_DEFAULT (`*.maintenance_required`) | Exists on `ProductToolSpec`/`ProductEquipmentSpec` only. Rim/Tire's own detailed Specification tables (the authoritative, field-by-field sections earlier in the same documents) never list a Maintenance Required field for Rim or Tire — this summary row's "Optional" mark for Rim/Tire is not corroborated by either doc's own per-type table | **Deliberately not added** — see REMAINING below |
| Calibratable | Tool/Equipment "Optional", others No | ITEM_TYPE_DEFAULT (`*.calibration_required`) | Already implemented (Tool/Equipment only), wired in Create/Edit | None — already correct |

### Architecture audit before touching anything

Per the "audit before schema design" discipline: inspected `Product`,
`ProductConsumableSpec`, `ProductToolSpec`, `ProductEquipmentSpec`,
`App\Domain\Tire\Models\ProductRimSpec`/`ProductTireSpec`,
`WarehouseStock`, `WarehouseStockController`,
`ProductSpecificationService`, `CreateProductModal.tsx`,
`EditProductModal.tsx`, and the full `PermissionSeeder` inventory
group, before writing any code. This confirmed: (a) the correct
Product-Master-vs-Inventory-Instance boundary was already respected —
Minimum/Reorder/Maximum Stock were correctly modeled as `WarehouseStock`
columns from the start (Phase 4), never as Product columns, consistent
with a Product being stocked differently at different Warehouses; (b)
every other row in the table already had a real backing field, so no
new migration, model, or duplicate source of truth was needed anywhere
in this batch.

### Implementation

- `WarehouseStockListPage.tsx`: added Min/Reorder Pt./Max columns to the
  table (SYSTEM_DERIVED display, read from the existing `WarehouseStockItem`
  type — already fully typed, unused until now), and a new "Thresholds"
  row action (gated by the existing `inventory.adjust` permission — the
  same one the route itself already required — no new permission
  invented), following the file's established `AdjustModal`/`ScrapModal`
  pattern exactly: a `target`-driven `Modal` with local form state
  hydrated via `useEffect` from the target row, calling
  `PUT /app/inventory/{id}/thresholds`, blank input mapped to `null`
  (clearing a threshold, not `0`).
- No backend production code changed — `WarehouseStockController`,
  `WarehouseStock`, and the migration were already correct and complete.
- `tests/Feature/InventoryTest.php`: 6 new focused tests for the
  previously-uncovered `updateThresholds` endpoint — update persists
  correctly, requires `inventory.adjust` permission, rejects negative
  values (422), a threshold can be cleared back to `null`, thresholds
  are per-Warehouse (setting them on one Warehouse's stock row for a
  Product does not affect another Warehouse's row for the same
  Product), and the update is denied when the acting user's warehouse
  data-scope doesn't include the target stock's Warehouse.

### Tests

Focused: `InventoryTest` 18/18 PASS (46 assertions; 12 pre-existing +
6 new), executed with the Mongo migrations relocated
(ext-mongodb unavailable) and restored immediately after with `git
status --porcelain` confirming zero diff. `pint --test` on the touched
test file: pre-existing fixer flags only, confirmed via `git stash`
comparison (identical before/after — no new style debt). Frontend:
`tsc --noEmit` clean, `oxlint` on the touched file shows one
`set-state-in-effect` warning — the exact same pattern already used
throughout `EditProductModal.tsx` and other existing modals in this
codebase for hydrating local form state from a changing `target` prop,
not a new anti-pattern introduced by this batch. `npm run build`
(`tsc -b && vite build`) — PASS.

Full-suite regression was not re-run for this batch: no backend
production code was modified (only a new test file's assertions and a
frontend-only page), so the risk surface is limited to
`WarehouseStockListPage.tsx`'s own rendering and the already-passing
`InventoryTest` suite: RAN.

### REMAINING from this batch (disclosed, not blocking)

- **Maintainable for Rim/Tire**: the Inventory Configuration summary
  table marks this "Optional" for Rim and Tire, but neither document's
  own detailed, field-by-field Rim/Tire Specification table (the
  authoritative source used for Batches 4-5) lists a Maintenance
  Required field for either type. Adding one now would mean inventing
  a field the primary specification tables never defined, based only
  on a summary row's imprecise wording — the kind of guess the owner's
  own instructions say to avoid. **NEEDS_OWNER_DECISION** if the intent
  really is periodic Rim/Tire maintenance tracking (e.g., re-torque or
  balance-check intervals) distinct from Tire's own disposition/
  retread/repair governance (already extensively implemented in
  `TireDispositionEligibilityTest`/`TireRepairRetreadGovernanceTest`),
  not implemented speculatively here.
- **Sparepart Expiry Tracked**: the summary table marks this "Optional"
  for Sparepart, but `track_expiry` only exists on
  `ProductConsumableSpec` (Consumable-only) — `ProductSparepartSpec`
  has no such field, and neither does the Sparepart Specification table
  in either source doc list a Shelf Life / Expiry concept as
  configurable (the "-Products" doc's Sparepart table does list
  `Shelf Life | O | Number + Unit Dropdown`, distinct from a
  Yes/No Expiry Tracked toggle). Same reasoning as above — flagged, not
  invented.

## Batch 13 Detail (Consumable Safety Data Sheet upload)

### Scope

Doc requirement (both "-Products" and the plain "Next Improvement
Tenant Portal" documents, Consumable Specification table): `Safety
Data Sheet | O | File Upload | SDS/MSDS jika tersedia`. Audited before
writing any code: `ProductConsumableSpec.sds_file_path`/
`sds_original_filename` columns and their `ProductSpecificationService`
validation rules already existed (from an earlier batch), but were
**entirely dead** — accepted as plain nullable strings with nothing
ever populating them: no upload service, no controller action, no
route, no frontend field anywhere in the repository. This is the exact
same "columns exist, entry point missing" shape as Batch 12's
Warehouse Stock Thresholds gap.

### Architecture decision: single-file overwrite, not a multi-document table

Considered two options: (a) a dedicated `product_consumable_sds`
evidence-style table (the `WorkOrderPartReturnEvidence`/
`VehicleDocument` multi-file pattern), or (b) two columns directly on
`ProductConsumableSpec`, overwritten on re-upload (the
`VehicleBrandLogoService`/`TenantLogoService` single-file pattern).
Chose (b): the two columns already existed from an earlier batch (no
new migration needed at all), and the doc describes SDS as a single
current document per Consumable Product ("SDS/MSDS jika tersedia" —
singular), not a history of documents — matching the semantics of
`VehicleBrandLogoService` exactly (delete-and-replace on re-upload) far
more than the multi-file Return-evidence pattern.

### Implementation

- New `ProductConsumableSdsService` (`app/Domain/ProductMaster/Services/`):
  `upload()`/`delete()`, private `local` disk (a hazmat safety document
  is never publicly servable — same posture as `VehicleDocumentService`),
  MIME allowlist `[jpeg, png, webp, pdf]` (matching
  `VehicleDocumentService`/`PaymentSubmissionService`/
  `VendorInvoiceReferenceService`'s established document-upload
  allowlist, not the image-only allowlist used for pure photos), 10MB
  max (matching the same document-upload precedent, not the 5MB
  image-only limit). Rejects upload for any non-`CONSUMABLE` product
  (`sds_file_path` exists only on `ProductConsumableSpec`).
- `ProductController`: `uploadSds()`/`showSds()`/`destroySds()`, mirroring
  `VehicleBrandController`'s logo upload/show pattern exactly —
  `POST` and `DELETE` gated by `product.update`/`product.delete`
  respectively (no new permission invented, reusing the existing
  `product` permission group), `GET` gated by `product.view`.
  `showSds()` streams via `Storage::disk('local')->response(...)`
  (Laravel auto-detects Content-Type; no separate `mime_type` column
  needed since disk is always fixed to `local`).
- Routes: `POST/GET/DELETE /products/{product}/sds`.
- Frontend: new "Safety Data Sheet" card in `ProductDetailPage.tsx`,
  shown only when `product.product_type === 'CONSUMABLE'` (matching the
  existing conditional-card pattern already used there for `TIRE`'s
  Reference Tread Depth field). Shows the current filename
  (SYSTEM_INFORMATION) with Download/Remove actions when a file exists,
  or an empty state; a file input (accept
  `.jpg,.jpeg,.png,.webp,.pdf`) uploads/replaces, gated by
  `product.update`/`product.delete` respectively — not embedded in the
  Create form (SDS requires an existing Product ID, so — same as
  Vehicle Brand's logo — it is necessarily a post-create action,
  correctly placed on the Detail page, not `CreateProductModal`).
  Download uses the established authenticated-blob-fetch pattern
  (`responseType: 'blob'`, `URL.createObjectURL`, `window.open`) already
  used by every PDF "print" action in this codebase, generalized to
  read the actual response `Content-Type` header rather than hardcoding
  `application/pdf` (since an SDS can also be an image).
  `types/index.ts`'s `ProductConsumableSpecItem` gained
  `sds_original_filename` (the file path field already existed but was
  unused before this batch).

### Tests

7 new (`ProductConsumableSdsTest`): upload + authenticated download
round-trip, re-upload replaces and deletes the previous file
(`Storage::assertMissing`), disallowed MIME type rejected (422),
upload rejected for a non-Consumable product type (422), permission
enforcement (403), delete clears both columns and removes the file,
tenant isolation (404 for both upload and download from another
tenant). Regression: `ProductDynamicSpecificationTest` (32/32) and
`ProductEditDynamicFormTest` (8/8) re-run clean — no interference with
the existing Product Create/Edit flows sharing `ProductController`.
`pint --test` on all touched/new backend files: `ProductController.php`
and `routes/api/app.php` show only the same pre-existing fixer flags
confirmed via `git stash` earlier in this continuation (Batch 11);
`ProductConsumableSdsService.php`/`ProductConsumableSdsTest.php` (new
files) are clean. Frontend: `tsc --noEmit` initially reported clean but
**`npm run build`'s `tsc -b` caught a real type error** (`Blob`'s
`type` option rejecting Axios's `AxiosHeaderValue | undefined`,
which includes `null`) that `--noEmit` alone missed — fixed by
narrowing to `string | undefined` before use. This is concrete,
observed confirmation of the "`tsc` PASS is not equivalent to
production build PASS" rule from the checkpoint-resume instructions:
`npm run build` is the authoritative frontend type-check going forward,
not a bare `tsc --noEmit` in isolation. `oxlint` on all touched files:
0 new warnings (one `set-state-in-effect` warning on
`ProductDetailPage.tsx` confirmed pre-existing via `git stash`
comparison). `npm run build` — PASS after the fix.

MongoDB: NOT RUN (ext-mongodb unavailable, network-blocked from
installing); relocate-run-restore precedent followed, zero diff on
`database/migrations/` confirmed via `git status --porcelain` before
committing.

### REMAINING from this batch (disclosed, not blocking)

- None. This batch's scope (wiring the already-existing SDS columns to
  a real upload/download/delete flow) is complete end-to-end.

## Batch 14 Detail (OTHER item type resolution + repository-wide Image URL sweep)

### Part 1: Product Item Type OTHER — usage/impact investigation

Investigated before any removal decision, per the resume mandate. Found:

- `OTHER` originates in the ORIGINAL Phase 4 migration
  (`2024_04_01_000001_create_product_master_tables.php`, `product_type`
  enum `['SPARE_PART','TOOL','TIRE','CONSUMABLE','EQUIPMENT','OTHER']`)
  — it predates the "Next Improvement Tenant Portal" documents entirely
  and was the original catch-all before RIM existed as its own type.
  Neither source document ever lists "Other" among the doc's own
  explicit 6-value Item Type dropdown ("Sparepart / Consumable / Rim /
  Tire / Tool / Equipment").
- A prior batch's migration (`2026_09_25_000001_extend_product_type_and_categories.php`)
  already documented this exact history in its own docblock when adding
  RIM, and deliberately left OTHER in the CHECK constraint "every
  existing... OTHER row is unaffected" — i.e., backward compatibility
  for OTHER was already a considered, explicit decision, not an
  oversight.
- Usage search across `app/`, `database/seeders/`, and `tests/`: **zero**
  seeder, demo-data generator, or test ever creates an OTHER-typed
  Product. It was, however, still **actively selectable** in
  `CreateProductModal.tsx`'s `ITEM_TYPES` dropdown (a tenant user could
  create a new OTHER product today, getting only General Information
  fields — no specification section, since `ProductSpecificationService`
  has no OTHER case), directly contradicting the doc's own explicit
  6-option dropdown list.

**Decision:** exclude OTHER from new creation (both layers — the
frontend dropdown AND `StoreProductRequest`'s validation), while
leaving the database CHECK constraint untouched (it still accepts
OTHER, so if any tenant somewhere already has one — none found in this
repository's own data — it remains fully readable and editable; Item
Type is immutable on Edit regardless, per the pre-existing rule). This
is the smallest correct change: no destructive migration, no data
touched, only the creation surface tightened to match the doc.

### Part 2: Repository-wide Image URL sweep

Searched every tenant-portal page for `image_url`/`logo_url`/
`photo_url`/"Image URL"/"Logo URL"/"Photo URL" references. Found:

| Location | State found | Action |
|---|---|---|
| `CompanyProfilePage.tsx` (`logo_url`) | Display-only (`<img src=...>`), upload already exists (Batch 8) | None — already correct |
| `VehicleBrandsPage.tsx` (`logo_url`) | Authenticated blob-fetch preview, upload already exists (prior batch) | None — already correct |
| `VehicleDetailPage.tsx` (`photo_url`) | Authenticated blob-fetch preview, upload already exists (prior batch) | None — already correct |
| `ProductDetailPage.tsx` (`image_url`) | Display-only `<img src={product.image_url}>` | Superseded — see below |
| `EditProductModal.tsx` (`image_url`) | **Raw editable text `FormField`** — the one remaining genuine gap | **Converted this batch** |

### Implementation (Product Image)

- New migration `2026_09_28_000005_add_image_path_to_products.php`:
  additive `image_path`/`image_original_filename` nullable columns on
  `products`. `image_url` itself is deliberately left completely
  untouched (schema and semantics) — any pre-existing row with a real
  external URL keeps rendering exactly as before; the new columns are
  populated only once a tenant uploads a replacement image through the
  new flow. Considered reusing `image_url` itself (as `TenantLogoService`
  does for `Tenant.logo_url`, writing a public-disk URL string into the
  same column) but rejected it: a Product image is tenant-internal
  catalog data, not public branding like a Tenant's logo (which
  deliberately needs to render in unauthenticated contexts — the
  favicon, the pre-login page paint) — the correct, secure posture is
  the private `local` disk + authenticated-blob-fetch pattern already
  established for Vehicle Brand Logo and Vehicle Photo, which requires
  a real path/URL distinction, not a single reused string column.
- New `ProductImageService` (`upload()`/`delete()`), mirroring
  `VehicleBrandLogoService` exactly: private `local` disk, JPG/PNG only,
  5MB max, single-file overwrite-on-reupload with old-file cleanup.
- `ProductController`: `uploadImage()`/`showImage()`/`destroyImage()`,
  same `product.update`/`product.view`/`product.delete` permission
  mapping as the SDS endpoints (Batch 13) and Vehicle Brand's logo
  routes. Routes: `POST/GET/DELETE /products/{product}/image`.
- `StoreProductRequest`: `product_type` tightened to the 6 documented
  values (OTHER excluded — see Part 1).
- Frontend: `EditProductModal.tsx`'s raw "Image URL" `FormField` and its
  `imageUrl` state/payload field removed entirely (upload needs an
  existing Product ID, so — same reasoning as Batch 13's SDS card — it
  cannot live in the Create/Edit form; it belongs on the Detail page).
  `ProductDetailPage.tsx` gained a `ImageUploadField`-based control
  (the same reusable component from Batch 10, `multiple={false}`)
  replacing the old plain `<img src={product.image_url}>` line, backed
  by a new authenticated-blob-fetch `useEffect` (identical pattern to
  `VehicleBrandsPage.tsx`'s `logoPreview` effect) that prefers the new
  `image_path` when present and falls back to the legacy `image_url`
  for any product that only has the old field — so an existing row's
  image never disappears, it just isn't re-fetched through the new
  authenticated endpoint. `CreateProductModal.tsx`'s `ITEM_TYPES` const
  (shared by the Create dropdown and the Product List page's type
  filter) had `'OTHER'` removed.

### Tests

15 new: `ProductImageTest` (8 — upload/download round-trip, re-upload
replaces and deletes the previous file, disallowed MIME rejected (422),
permission enforcement (403), delete clears both columns and removes
the file, tenant isolation (404), **the legacy `image_url` column
remains directly writable through the general update endpoint** —
explicit backward-compatibility test, same precedent as Vehicle
Brand/Company Profile's `logo_url`, and OTHER rejected on create
(422)). Regression: `ProductDynamicSpecificationTest` (32/32),
`ProductEditDynamicFormTest` (8/8), `ProductConsumableSdsTest` (7/7),
and `ProductFoundationMasterDataTest` (9/9 — includes
`product_type_accepts_rim`) all re-run clean, confirming the OTHER
restriction and the new image columns don't interfere with any
existing Product flow. `pint --test`: `ProductController.php`/
`routes/api/app.php` show only the same pre-existing fixer flags
already confirmed via `git stash` in Batches 11 and 13; every other
touched/new file is clean. Frontend: `npm run build` (the authoritative
check per Batch 13's finding) — PASS; `oxlint` — 0 new warning
categories (one additional `set-state-in-effect` instance on the new
blob-fetch effect, same established pattern used throughout this
codebase, confirmed via `git stash` comparison against the pre-existing
count).

MongoDB: NOT RUN (ext-mongodb unavailable, network-blocked from
installing); relocate-run-restore precedent followed, zero diff on
`database/migrations/` confirmed via `git status --porcelain` before
committing.

### REMAINING from this batch (disclosed, not blocking)

- None. Both parts of this batch (OTHER resolution, Image URL sweep)
  are complete; the sweep found and closed exactly one remaining gap
  (Product's `image_url`) — every other entity's image/logo/photo field
  in the tenant portal was already converted in an earlier batch.

## Batch 15 Detail (Final PR-readiness audit)

Full detail lives in
`docs/status/TENANT_PORTAL_ALIGNMENT_FINAL_REPORT.md` §N (the
authoritative, current closure section — §§A-M of that document predate
Batch 9 and are kept only as historical record). Summary:

- **Full non-Mongo backend regression, run clean and watched to
  completion: 800/800 PASS, 3583 assertions, 0 failures.** Mongo
  migrations and `tests/Feature/Analytics`/`Intelligence` relocated per
  the established precedent, restored immediately after with
  `git status --porcelain` confirming zero diff.
- Frontend: `npm run build` PASS, `npm run lint` 0 errors (28
  pre-existing warnings, no new categories).
- Git diff review (`git diff main..HEAD`, 93 files, the full branch):
  no debug code, no secrets, no unrelated files — every change traces
  to a documented batch.
- Final Requirement Closure Matrix built (COMPLETE /
  NEEDS_OWNER_DECISION / BLOCKED_EXTERNAL_DEPENDENCY / NOT_COMPLETE) —
  see Final Report §N.2. Four small, disclosed, non-blocking
  NEEDS_OWNER_DECISION items remain (Removed Component costing,
  Removed Component serialized-asset integration, Rim/Tire
  Maintainable, Sparepart Expiry Tracked); two deliberately-deferred
  NOT_COMPLETE follow-ups (Removed Component disposition workflow, full
  per-status Tab/button visibility matrix); Analytics/Intelligence
  correctly classified BLOCKED_EXTERNAL_DEPENDENCY (out of this
  initiative's scope, pre-existing `ext-mongodb` environment
  limitation).
- **Decision: READY FOR PULL REQUEST.** Per explicit instruction, the
  Pull Request itself was NOT created — this is the readiness decision
  only, awaiting separate explicit approval to create it.
