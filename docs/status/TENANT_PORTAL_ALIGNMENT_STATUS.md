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
  hardcodes the old enum (never wired). Component Groups has no "New"
  creation UI despite backend support. Workspace "Capacity Unit" is
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
| 2 | Work Order per-action status gates (422 fix), Overview restructure, Est. Number of Mechanic/Total Hours, Consume/Return popups | NOT STARTED |
| 3 | Workshop Invoice View History | NOT STARTED |
| 4-5 | Product Edit dynamic form, Active toggle, Inventory Configuration section | NOT STARTED |
| 6 | Worker Type frontend wiring, Component Group create UI | NOT STARTED |
| 7 | Maintenance Packages legacy popup cleanup (low priority) | NOT STARTED |
| 8 | Tenant logo upload + favicon wiring | NOT STARTED |

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
