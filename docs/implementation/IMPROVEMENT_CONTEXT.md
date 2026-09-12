# OptiFleet Improvement Context

## Authoritative Source
- "OptiFleet — Consolidated Read-Only Gap Analysis Report (Baseline for
  Future Improvement Project)" (.docx) — supplied externally by the owner
  this session, not tracked in the repository. Defines gaps G-01–G-43,
  Business Decisions BD-1–BD-8, dependency order, and Phase A–G roadmap.
- "Analisis Menyeluruh VMS untuk Improvement OptiFleet" (.docx) — supplied
  externally this session, not tracked in the repository. Read-only
  Super Admin observation of a reference VMS product (Indonesian);
  supplies VMS-R recommendations and UI/workflow observations layered
  under the consolidated report's authority per its own §17.
- Base branch/commit: `main` @ `8648228c222fd464139bf9443050a50d99b33845`.
- Active branch: `Improvement` (created from the commit above).
- Remote branch: not yet pushed (push occurs only at a completed phase
  boundary per the task's Section 12 policy).

## Mandatory Business Decisions (BD-1–BD-8, condensed)
- BD-1: Full SPA/KA/KTS/KTN/KF apparatus is mandatory; critical-fail gate
  overrides KF always.
- BD-2: Repair and Retread use separate, versioned scoring configurations;
  Repair does not require ΔKT/tread improvement.
- BD-3: Tread depth stored in mm; reference sourced from Tire Product
  spec; system-calculated %; reject calculation on missing/zero/invalid
  reference (never silently approximate).
- BD-4: Maker-checker named ownership across removal/inspection/send-
  receive/final-inspection/return-to-service/sell/dispose; receiver
  cannot self-approve final acceptance.
- BD-5: "Sell" splits into SELL_FOR_OPERATIONAL_REUSE /
  SELL_AS_RETREADABLE_CASING / SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL;
  Very Bad tires never sold for operational reuse.
- BD-6: Disposition hierarchy: Critical Safety Failure → Legal/Policy →
  Casing Eligibility → Lifecycle Limits → Vehicle/Axle/Operational
  Suitability → Inspection Result → KF → Economic Feasibility → Approval
  → Final Disposition.
- BD-7: Legacy onboarding, atomic multi-tire rotation, and REPAIR
  disposition are in scope, in dependency order: Serial Integrity →
  Wheel Position Validation → Legacy Onboarding → Atomic Rotation →
  REPAIR Lifecycle → Inspection/Approval → Scoring Configuration.
- BD-8: Platform Default + Optional Versioned Tenant Override; named
  non-overridable platform safety invariants (critical-fail gate,
  unsafe-install/resale prohibitions, serial/position integrity,
  maker-checker, audit, non-overridable legal restrictions,
  finalized-result immutability); every scored record persists its
  configuration version.

## Architecture Invariants
- Tenant isolation: every tenant-scoped table/query must carry
  `tenant_id`; global `TenantScope` via `BelongsToTenant`, never
  client-trusted.
- Permission enforcement: server-side, permission-string based (never
  hardcoded role names), via `CheckPermission` middleware.
- Workflow/state integrity: `WorkOrderTransitionService` is the single
  transition entry point; row-locks the WO before re-checking status.
- Inventory ledger integrity: `stock_movements` is append-only; every
  balance mutation in `InventoryService` row-locks `WarehouseStock`
  first; balances must reconstruct from the ledger.
- Concurrency: critical read-then-write sequences (`returnPart`,
  `consume`, retread cycle numbering) must lock the row they check
  before checking it, inside one transaction.
- Tire safety: one serial = one physical asset; one active position per
  tire; one active tire per position (DB partial unique indexes);
  critical-fail overrides all scores; unsafe tires never installed or
  resold for operational reuse.
- Auditability: `Auditable` trait on models where field-level history
  matters; every disposition/scoring record must carry actor, reason,
  before/after, and (once built) configuration version.
- Money/quantity precision: `decimal` columns throughout, never native
  float; existing `brick/math`-based `Money` helper is the project's
  established pattern for monetary arithmetic.

## Phase Status

| Phase | Gap IDs | Status | Commit(s) | Tests | Remaining work | Blocker |
|---|---|---|---|---|---|---|
| A — Critical Integrity & Closure Controls | G-14, G-18, G-17, G-35, G-21, G-22, G-19 | COMPLETE | see commits below | Targeted: 48 passed (273 assertions). Regression (same 40-file baseline set): 263 passed / 882 assertions vs. baseline 262 passed / 878 — net +1 test (the new G-19 test added to an existing file), 0 failures, 0 regressions. Frontend: tsc build + production build clean. | G-21's running-balance column is computed client-side over a widened (200-row) page per item/warehouse, not a true from-inception ledger balance — accurate for realistic history lengths, not mathematically guaranteed beyond that without a backend-computed opening-balance parameter (not built this session). | none |
| B — Used Sparepart Processing | G-15 | COMPLETE | see commits below | Targeted: 9 new tests passed (68 assertions), plus 54 regression on touched domains (WorkOrderStockIntegrationTest/InventoryReturnClassificationTest/WorkOrderClosureGuardTest/WorkflowEngineTest/WorkflowMigrationTest/InventoryTest), all passing. Full regression (40-file baseline set): see verification status. Frontend: tsc + build clean. | REPAIR disposition is decision-only (no repair-partner receipt sub-flow — out of scope, not described in the source report beyond "Repair" as one disposition value). | none |
| C — Sell Sparepart | G-16, G-20 | COMPLETE | see commits below | Targeted: 8 new tests (SparePartSaleTest, 64 assertions) + 9 UsedPartDispositionTest regression, all passing. Full regression (40-file baseline set): see verification status. Frontend: tsc + build clean. | Settlement/payment (did money actually change hands) is explicitly out of scope, per the source report's own instruction not to invent unsupported accounting behavior — `SparePartSale` tracks price/total/approval only. | none |
| D — Tire Asset Integrity | G-26, G-25, G-23, G-24, G-28, G-34 (evidence-only) | COMPLETE | see commits below | Targeted: 18 new tests (TireAssetIntegrityTest, 50 assertions) + 7 regression on TireTest, all passing. Full regression (updated baseline set): see verification status. Frontend: tsc + build clean. | Wheel position validation is permissive (not enforced) for any vehicle category with zero configured `wheel_configurations` rows — no platform-default layout is seeded (flagged, needs fleet-engineering input). | none |
| E — Tire Repair/Retread Governance | G-27, G-29, G-32, G-36, G-30, G-33, G-37 | COMPLETE | see commits below | Targeted: 18 new tests (TireRepairRetreadGovernanceTest, 91 assertions) + 3 WorkOrderClosureGuardTest regression (updated to the new TireService signatures), all passing. Full regression (updated baseline set): see verification status. Frontend: tsc + build clean. | REPAIR disposition sub-flow: eligible-partner type list (EXTERNAL_WORKSHOP/TIRE_SUPPLIER) is a documented, non-fabricated inference from existing `partner_type` values, not a report-cited enumeration — flagged for product confirmation. Phase D's permissive wheel-position fallback is untouched by this phase (documented, not fixed — see Decisions). | none |
| F — Tire Scoring/Classification | G-31, BD-1–BD-6, BD-8 | FRAMEWORK COMPLETE, CONFIGURATION NOT APPROVED, PRODUCTION SCORING NOT ENABLED | see commits below | Targeted: 28 new tests (TireScoringAndSaleTest, 118 assertions), all passing. Full regression (updated baseline set): see verification status. Frontend: tsc + build clean. | G-11's structured tire spec fields (width/aspect ratio/rim diameter/load-speed index as discrete fields) remain deferred to Phase G — Phase F only added the one BD-3-mandated numeric field (reference tread depth) actually needed for scoring, not a full spec restructure. BD-6's legal/policy, casing-eligibility, lifecycle-limit, and vehicle/axle-suitability precedence steps have no encodable rule in the material available this session — see Decisions. | Zero production scoring: no TIRE_SCORING configuration is seeded/published by this session (deliberate — see Decisions); every tenant must explicitly publish its own before any tire can be scored. |
| G — Carried-Forward VMS Parity | G-01–G-09, G-11–G-13, G-38–G-43 | NOT STARTED | — | — | Schedule→WO, Cost Estimation, Maintenance Result, tiered PO approval, Workshop Partner cycle, remaining master-data UI, G-11's structured tire spec fields | Independent; not started this session |

## Current Work
- Active phase: F (implemented and validated this session; Phases A-E
  already pushed to `origin/Improvement`).
- Active batch: none in progress — Phase F is complete pending final
  regression confirmation and the phase-boundary push.
- Files/modules in progress: none.
- Immediate next action for the following session: re-read this file and
  Git history to confirm Phase F's push landed on `origin/Improvement`,
  then begin Phase G (Carried-Forward VMS Parity, G-01–G-09/G-11–G-13/
  G-38–G-43), which is independent of Phase F's scoring framework.

## Decisions and Deviations
- Decision: return-condition classification (G-14) is implemented as a
  new `work_order_part_returns` table (append-only "Received Sparepart
  Return" record) rather than a new `stock_movements` movement type for
  the USED_GOOD/USED_FAULTY branches.
  Reason: `stock_movements` balance-reconstruction logic elsewhere in the
  codebase (and its own tests) infers on-hand balance effect purely from
  `movement_type`; a used-condition return must never affect on-hand
  balance, so keeping it in a dedicated table avoids any risk of a future
  reconciliation reading a new movement type's sign incorrectly. Only the
  UNUSED_NEW branch writes a normal `RETURN` stock_movement (existing,
  unchanged ledger behavior).
  Report reference: §9, §26 (G-14), §29 Dependency Map.
  Repository evidence: `stock_movements_movement_type_check` constraint;
  `InventoryTest::test_stock_movement_ledger_reconstructs_balance`.
- Decision: G-22's consumption ledger entry uses a new `CONSUME`
  `stock_movements` type with zero on-hand effect (a traceability marker
  only), not a second deduction.
  Reason: on-hand quantity is already decremented at ISSUE time;
  decrementing again at CONSUME would double-deduct stock, violating the
  "prevent double deduction" invariant (CLAUDE.md, Inventory section).
- Decision: `returnPart()`/`consume()` return endpoint validation now
  requires `condition` (return only) and both now lock the
  `WorkOrderPlannedPart` row for update inside their transaction before
  reading `outstandingIssued()`.
  Reason: this is the literal G-18 TOCTOU race fix — the previous
  check-then-act ran on an unlocked, possibly-stale row.
  This is a documented, intentional breaking API change to
  `POST .../planned-parts/{id}/return` (now requires `condition` in the
  request body) — authorized explicitly by the task's Phase A scope
  ("return-condition classification" is a named G-14 deliverable) and
  the CLAUDE.md clause permitting migration of tenant-facing behavior
  "with a documented plan." No other endpoint contracts changed.
- Decision: WO closure guard (G-17/G-35) blocks `COMPLETED`/`CLOSED`
  transitions on **any** planned-part status outside
  {CONSUMED, RETURNED, CANCELLED} (not only ISSUED/PARTIALLY_ISSUED), and
  on any Tire removed under that WO whose current tire status is still
  UNDER_INSPECTION or RETREAD.
  Reason: the report's own summary phrase is "no Work Order closes with
  mandatory unresolved part" (unqualified), and the narrower
  ISSUED/PARTIALLY_ISSUED-only reading appears only in one restated
  passage (§29) — the broader, stricter reading is the safer one and is
  consistent with CLAUDE.md's "err toward stricter integrity guard"
  posture. Recorded here in case a future session finds evidence this
  was intended to be narrower.
  Report reference: §7 (G-17), §17 (G-35), §29 Dependency Map, §30 Phase A
  acceptance criteria.
- Decision (Phase B): the disposition approval step is the first real
  production caller of `WorkflowApprovalService` (confirmed by direct
  code search: previously exercised only by `WorkflowEngineTest` against
  a synthetic resource type). A platform-default `used_part_disposition`
  workflow configuration was added to `WorkflowDefaultsSeeder` purely so
  `WorkflowApprovalRequest.workflow_configuration_version_id` (NOT NULL
  + FK) has a real, versioned value to stamp — `UsedPartDispositionService`
  does not use `WorkflowEngine::isTransitionAllowedForVersion()` for its
  own status guards (those are plain, explicit checks, matching
  `WorkOrderPartService`'s own style for its non-primary transitions).
  Report reference: §31 ("No approval gate in Phases B... may be
  implemented as a bespoke, feature-specific mechanism").
- Decision (Phase B): maker-checker (a proposer cannot also approve/
  reject their own disposition) is enforced in
  `UsedPartDispositionService::decide()`, not inside
  `WorkflowApprovalService`.
  Reason: read `WorkflowApprovalService`/`ApprovalResolver` directly —
  neither compares `requested_by` against the deciding user; this is
  confirmed, not assumed. Every future caller of this shared engine will
  need the same self-check until/unless the engine itself is extended.
- Decision (Phase B): `SCRAP`/`REPAIR`/`QUARANTINE`/`SELL_ELIGIBLE`
  dispositions call **no** `InventoryService` method at finalize time;
  only `REUSE` does (via `returnStock`).
  Reason: a used-condition return never enters `quantity_on_hand` at
  Phase A return time. Calling `InventoryService::scrap()` on it would
  either fail (insufficient on-hand) or — worse — silently decrement
  unrelated good stock of the same product, since `scrap()` operates on
  the shared on-hand balance, not a per-return quantity. This was caught
  and fixed during this session (an earlier draft of `finalize()` called
  `scrap()` here; corrected before any test or commit). The
  `WorkOrderPartReturn` row itself (now `Auditable`) is the complete
  record for these four outcomes.
- Decision (Phase C): `SparePartSale` records `sale_type` (OPERATIONAL_REUSE
  or SCRAP_MATERIAL) but only implements the operational sale boundary —
  price/quantity/approval/immutable SALE ledger entry. No payment/
  settlement/invoice entity was built.
  Reason: the source report explicitly separates "sale approval,
  inventory movement, payment/settlement state" and says not to invent
  unsupported accounting behavior or reuse tenant-subscription billing
  for this. Recording this boundary explicitly rather than silently
  stopping partway.
- Decision (Phase C): a sale's approved SALE stock_movement carries zero
  on-hand balance effect (`InventoryService::recordSale()`, refactored
  alongside `recordConsumption()` into a shared `writeZeroEffectMovement()`
  helper), mirroring G-22's CONSUME pattern exactly.
  Reason: a `SparePartSale` can only reference a `SELL_ELIGIBLE`-
  disposition return, and that quantity was never added to
  `quantity_on_hand` (Phase A/B design) — there is no balance to deduct
  from without wrongly touching unrelated good stock of the same
  product. Same reasoning as Phase B's SCRAP/REPAIR/QUARANTINE decision.
- Decision (Phase C): `SparePartSale.sale_type = OPERATIONAL_REUSE` is
  blocked at creation time when the source return's `condition` is
  `USED_FAULTY`, even though Phase B's own gate already prevents a
  USED_FAULTY return from ever reaching `SELL_ELIGIBLE` in the first
  place.
  Reason: defense in depth — a direct database write or a future bug in
  Phase B's gate must not be the only thing standing between an unsafe
  part and a sale labelled fit for reuse. Verified with a test that
  crafts the row directly (bypassing the Phase B API) to prove this
  service-level check, not just the upstream gate, actually fires.
- Decision (Phase C): G-20 (`InventoryService::scrap()` route/permission/
  UI) was folded into this same phase rather than given its own —
  it operates on ordinary on-hand stock (unrelated to the Used Sparepart
  Processing quantity bucket) and was a small, self-contained addition
  (one route, one permission, one controller method mirroring the
  existing `adjust()` action, one modal on the Warehouse Stock page).

- Decision (Phase D): `assertValidWheelPosition()` is **permissive** (any
  free-text position accepted) for a vehicle category with zero configured
  `wheel_configurations` rows, and **strict** (position must exist in that
  category's configured set) once at least one row is configured for it.
  Reason: no `wheel_configurations` seeder exists in the repository (real
  axle/position layouts need domain/fleet-engineering input this session
  does not have) — a fail-closed default would immediately break every
  existing tenant's tire installs with zero notice or migration path.
  Flagged as needing a product decision: whether/when to seed
  platform-default layouts per vehicle category (see Known Blockers).
  Report reference: G-25 ("wheel position must be validated against the
  vehicle's configured layout").
- Decision (Phase D): G-24's atomic swap is implemented as a new
  `swapPositions()` service method / `POST .../swap-positions` endpoint,
  not an overload of `rotate()`.
  Reason: `rotate()`'s existing partial-unique-index contract only allows
  moving into an **empty** position; a genuine two-tire swap needs both
  active installations closed before either replacement is created (see
  code comment on `swapPositions()`), which is a materially different
  transaction shape worth naming distinctly rather than branching inside
  `rotate()`.
- Decision (Phase D): `TireService::replace()`'s signature changed to
  require a caller-supplied `string $disposition` (REUSE/RETREAD/SCRAP)
  instead of hardcoding REUSE.
  Reason: this is the literal G-28 gap — the old tire's outcome at
  replacement time is a real business decision (it may be scrap, not
  reusable), and `remove()` already supported all three dispositions;
  `replace()` was the one caller still forcing REUSE. This is a documented
  breaking change to `TireService::replace()` and to
  `POST .../tires/{tire}/replace` (now requires `disposition` in the
  request body) — no other caller of either existed in the repository.
- Decision (Phase D): legacy-onboarding baseline data (`installed_at`,
  `installed_at_source`, `baseline_tread_depth_mm`, `baseline_condition`)
  are all optional, independent parameters on `install()` — a normal live
  install passes none of them and behaves exactly as before (installed
  now, source KNOWN, no inspection record created). A baseline
  `TireInspection` row is created **only** when the caller actually
  supplies a tread-depth or condition reading; nothing is fabricated when
  neither is given (`test_legacy_onboarding_without_baseline_reading_
  creates_no_fabricated_inspection`).
  Report reference: G-23; task instruction "do not fabricate historical
  installation dates, tread measurements, tire identities, odometer
  readings... support known, estimated, and unknown historical values
  explicitly."
- Decision (Phase D): G-34 required only an evidence check plus a
  frontend entry point — `TireService::replace()` and its endpoint
  already existed pre-Phase-D; the only gap was that no page in the
  frontend ever called it. Added a "Replace Tire" section to
  `TireDetailPage.tsx` calling the existing endpoint (now carrying the
  Phase D disposition field); no new backend code was needed for G-34
  specifically beyond what G-28 already added to the same endpoint.
- Decision (Phase D, VMS cross-reference): added a nullable
  `tires.manufacture_date_code` string column and request/model field.
  Source: VMS "Tire" reference screen's Production Date Code field, which
  has no OptiFleet equivalent. Never inferred or back-filled for existing
  rows (migration adds it `nullable` with no default population); always
  exactly what the user types, never validated against a DOT-style format
  since VMS does not document one specific format to require.
  Report reference: VMS §Tire Data Fields; not a numbered gap ID (no
  corresponding G-xx entry in the consolidated report — implemented as a
  narrow, safe, additive VMS-sourced adjustment per the task's own
  "implement adjustments that belong to Phase D and have a clear, safe
  business rule and data source" instruction).
- Decision (Phase D, VMS cross-reference): declined to copy VMS's "Wheels
  Configuration" pattern (vehicles classified into a fixed type — Non
  Trailer/Trailer/Semi-Trailer/Truck Head — each with a computed total
  wheel/tire count).
  Reason: OptiFleet's existing `wheel_configurations` table (per vehicle
  category, per named position, tenant-or-platform scoped) is already
  strictly more granular and flexible than VMS's four-type enum with
  derived totals; copying VMS's coarser model would be a regression, not
  an improvement, and CLAUDE.md's own guidance is not to copy VMS
  behavior that conflicts with current architecture. No code change.

- Decision (Phase E): Phase D's `assertValidWheelPosition()` permissive-
  when-unconfigured fallback is explicitly **not** changed, fixed, or
  compensated for by Phase E's approval gate.
  Reason: the task instruction for this phase required reviewing that
  decision and recording its impact rather than silently treating it as
  resolved. `approveCycle(RETURN_TO_SERVICE)` only changes
  `tires.current_status` back to IN_STOCK — it does not perform or
  revalidate an installation, so a tire returned to service by Phase E's
  gate can still be installed to an unvalidated, free-form position on
  any vehicle whose category has zero configured `wheel_configurations`
  rows, exactly as it could before this phase. The two controls
  (return-to-service safety approval vs. wheel-position integrity) are
  orthogonal; approval is not a substitute for verified position
  integrity. Proven directly by
  `TireRepairRetreadGovernanceTest::test_return_to_service_approval_does_not_revalidate_wheel_position_on_next_install`.
  This does not block any Phase E acceptance criterion — none of G-27/
  G-29/G-30/G-32/G-33/G-36/G-37 concern wheel position — but is recorded
  here so it is never mistaken for a Phase E deliverable. The
  platform-default-layout product decision from Phase D (see Known
  Blockers) remains open and unaffected.
- Decision (Phase E, G-27): REPAIR is implemented as a fully distinct
  lifecycle from RETREAD — its own `tire_repairs` table, its own
  `TireRepair` model, its own independent cycle-number sequence — rather
  than a `cycle_type` discriminator column added to `tire_retreads`.
  Reason: the task instruction explicitly calls for "a distinct REPAIR
  lifecycle," and a shared table would couple two operationally
  different processes (a retread partner reworks the tread; a repair
  partner fixes damage) to one schema, making future REPAIR-only fields
  awkward to add without nullable columns that only apply to one type.
  The two models share an identical governance shape (send/receive/
  final-inspect/approve) by construction, and `TireService` factors that
  shared logic into private `receiveCycle()`/`finalInspectCycle()`/
  `approveCycle()` helpers typed over `TireRetread|TireRepair` — so the
  duplication cost of "distinct" is paid only in the migration/model
  layer, not in the business logic.
- Decision (Phase E, G-29): concurrency-safety for cycle-number
  assignment is achieved by locking the parent `tires` row (`SELECT ...
  FOR UPDATE`) for the whole `retread()`/`repair()` transaction before
  computing `max(cycle_number)+1`, not by locking the cycle table
  directly.
  Reason: PostgreSQL rejects `FOR UPDATE` combined with an aggregate
  function (`MAX`) in the same query — there is no row to lock when the
  query returns one aggregated value. Locking the tire row instead
  achieves the same serialization: any concurrent send for the same tire
  blocks on that lock until the first transaction commits, so two sends
  can never compute the same next cycle number. An additional explicit
  guard (no second cycle number is even attempted while one for that
  tire is still open, i.e. not APPROVED/REJECTED) prevents a tire being
  sent out twice while a cycle is still outstanding — a bug that would
  otherwise exist independently of the numbering race itself. Verified
  by `test_second_retread_send_is_rejected_while_a_cycle_is_still_open`
  and `test_cycle_numbers_increment_across_successive_retread_cycles_for_the_same_tire`.
- Decision (Phase E, G-30): eligible partner types for retread/repair
  work are `EXTERNAL_WORKSHOP` and `TIRE_SUPPLIER` — the two existing
  `partner_type` enum values that plausibly perform physical tire
  service — checked alongside `status = ACTIVE`.
  Reason: the consolidated report requires "eligible Partner validation"
  but does not, in the material available to this session, enumerate a
  specific new partner-type taxonomy for tire service work; inventing a
  new enum value (e.g. `TIRE_RETREAD_SHOP`) without that source would be
  fabricating business classification. The two chosen values are the
  closest existing, already-seeded, already-validated types. **Flagged
  for product confirmation**: if the report or a later VMS reading
  specifies a different or additional eligible type, this allowlist
  (`TireService::ELIGIBLE_SERVICE_PARTNER_TYPES`) is the single place to
  update it.
  Also tightened: `partner_id` is now **required** on both send
  endpoints (previously nullable) — an eligibility check has no meaning
  against an absent partner, and a retread/repair cycle by definition
  goes to an external party.
- Decision (Phase E, G-32): maker-checker is enforced as a single
  explicit check inside `approveCycle()` — the approving actor must not
  equal `received_by` — plus RBAC separation via four distinct
  permissions per cycle type (`tire_retread.{send,receive,inspect,
  approve}`, `tire_repair.{send,receive,inspect,approve}`), rather than
  routing the approval step through `WorkflowApprovalService`.
  Reason: consistent with the Phase B precedent (`WorkflowApprovalService`
  does not itself compare actors — confirmed again by inspection, not
  re-litigated) and with this feature's own shape: a single-approver gate
  after a fixed four-actor sequence does not need a configurable
  multi-step approval engine. The explicit check is the same pattern as
  `UsedPartDispositionService::decide()`. Demo data now assigns send/
  receive to the Warehouse Manager role and inspect/approve to the
  Workshop Manager role, giving real separation of duties out of the box
  (see `DemoDataSeeder.php`).
- Decision (Phase E, G-33): `approval_reason` is a required, non-blank
  string persisted on the cycle record at the moment of approval —
  enforced in `approveCycle()` (`trim($reason) === ''` rejected), not
  merely a nullable free-text column relying on frontend discipline.
  Verified by `test_approval_reason_is_required_and_persisted`.
- Decision (Phase E, G-36): receiving a tire back from retread/repair
  moves `tires.current_status` to `UNDER_INSPECTION` (an existing status,
  reused rather than a new one invented for this purpose) — never
  directly to `IN_STOCK`. Only `approveCycle()`, after a final inspection
  has been recorded, can move it to `IN_STOCK` (RETURN_TO_SERVICE),
  `SCRAPPED` (SCRAP), or the new `QUARANTINED` status (QUARANTINE). An
  `UNSAFE` final-inspection result can never be approved for
  RETURN_TO_SERVICE regardless of who attempts it — a critical-safety
  gate enforced in code, not left to the approver's judgment. Because
  `TireService::install()`'s status guard (`IN_STOCK`/`RESERVED` only) is
  the sole path to `INSTALLED`, and no other code path writes `IN_STOCK`/
  `INSTALLED` for a tire, an UNSAFE or QUARANTINED tire cannot be
  installed through any alternate endpoint — verified directly by
  `test_quarantined_tire_after_repair_cannot_be_installed` and
  `test_unsafe_final_inspection_cannot_be_approved_for_return_to_service`.
  `WorkOrderClosureGuardService::UNRESOLVED_TIRE_STATUSES` is extended to
  include `REPAIR` and `QUARANTINED` alongside the existing
  `RETREAD`/`UNDER_INSPECTION`, so a Work Order cannot close while a tire
  it removed is in any of these states either — this was a necessary,
  in-scope consequence of introducing the two new statuses, not a
  standalone gap.
- Decision (Phase E, G-37): `Auditable` is added to both `TireRetread`
  and the new `TireRepair` model (previously `TireRetread` had no audit
  trail at all). Every governance field change — receive, final
  inspection, approval — is therefore captured with before/after values
  automatically, with no manual `AuditService::log()` calls needed
  anywhere in `TireService`, matching the established codebase
  convention. Verified by `test_retread_lifecycle_changes_are_audited`.

- Decision (Phase F, framework vs. production — read this first): this
  phase delivers the **calculation and safety-gate framework** for
  structured tire scoring, not an operational scoring formula. No
  TIRE_SCORING configuration is seeded, published, or otherwise shipped
  active by this session — `resolveEffective()` returns null for every
  tenant until that tenant's own admin (or, once one exists, a platform
  specialist) explicitly authors and publishes real bands/weights.
  `TireScoringService::calculate()` refuses to run at all without one.
  This is a deliberate, literal reading of the task's own instruction
  not to invent production scoring weights, legal thresholds, or
  tire-engineering rules the source documents leave unresolved, and to
  keep any unapproved formula inactive. **Framework implemented:
  yes. Configuration approved: no — none exists to approve. Production
  scoring enabled: no, for every tenant, until they publish their own.**
  The 28 Phase F tests validate the calculation engine, the safety
  gates, and the configuration validator using test-authored fixture
  configurations clearly scoped to those tests — none of those numbers
  are shipped as defaults or suggested as real thresholds anywhere in
  application code, seeders, or the frontend.
- Decision (Phase F, G-31/BD-1/BD-3): the KA/SPA/KTS/KTN/KF terms are
  implemented exactly as this project's own BD-1/BD-3 condensation
  already named them (recorded in this file since an earlier session):
  KTS = measured remaining tread depth (mm, from a `TireInspection`);
  KTN = reference/original tread depth (mm, from the linked Product,
  BD-3); SPA raw = KTS/KTN as a system-calculated percentage (BD-3's own
  words); SPA normalized = that percentage mapped through the published
  configuration's ordered bands into a 0-100 score plus a classification
  label; KA = a supplementary condition score **captured from the
  inspector, never computed by an OptiFleet-invented formula** — this
  project does not have a source for what should produce a KA number,
  so it accepts one as an input, the same way `TireInspection.condition`
  already does for other subjective readings; KF = a composite score
  computed **only** from whatever weights (if any) the published
  configuration defines over {spa_normalized, ka} — supporting
  information only, per BD-1, and structurally incapable of overriding
  the critical-safety-fail gate because that gate is computed and
  checked entirely independently of KF.
- Decision (Phase F, BD-1/BD-6): critical-safety-fail is the union of
  two independent signals — the inspector's own explicit flag (with a
  mandatory reason) and the matched SPA band's own `is_critical_fail`
  flag (if the published configuration marks that band as such). Either
  one sets `critical_safety_fail = true`, which then unconditionally
  forces `eligible_for_operational_reuse = false` regardless of the
  band's own eligibility flag, and unconditionally blocks
  `approveCycle(RETURN_TO_SERVICE)` the same way an UNSAFE final
  inspection already did (Phase E). This is the literal, hard-coded
  "critical-fail gate overrides KF always" and "must never override a
  failed safety gate" requirement — it is not configuration data and
  cannot be weakened by any tenant override.
- Decision (Phase F, BD-6 precedence chain — partial, documented gap):
  the report's full disposition precedence (critical safety → legal/
  policy → casing eligibility → lifecycle limits → vehicle/axle/
  operational suitability → inspection result → KF → economic
  feasibility → approval → final disposition) is only partially
  encodable with real rules today. This session implements, as actual
  enforced code: critical safety gate (absolute, above), inspection
  result / classification (drives `eligible_for_operational_reuse` and
  the sell/approve gates), KF (computed, supporting-only, never
  gating), and approval (the existing Phase E maker-checker approve
  step, plus the new scoring-linked block). Legal/policy restrictions,
  casing eligibility criteria, lifecycle limits, and vehicle/axle/
  operational suitability have **no rule this session can encode
  without inventing one** — no legal threshold, casing-age limit, or
  axle-position restriction is named in the material available this
  session. These four steps are not stubbed as fake "always pass"
  checks scattered through the codebase; they are simply not yet
  implemented, and are named here explicitly as the exact specialist/
  product input needed: a legal/compliance owner for the legal/policy
  step, a tire engineering source for casing-eligibility and lifecycle-
  limit thresholds, and a fleet-engineering source (same open item as
  Phase D's wheel-position defaults) for vehicle/axle suitability rules.
  Economic feasibility is explicitly a human approver's judgment call
  in this design, not an automated check (consistent with how
  `UsedPartDispositionService`'s approval step already works) — the
  maker-checker approval action IS that step.
- Decision (Phase F, BD-2): REPAIR and RETREAD scoring configurations
  are two separate `ConfigurationSet` codes under the same TIRE_SCORING
  type — structurally identical band/weight shape, but published,
  versioned, and resolved completely independently per BD-2's explicit
  requirement. "Repair must not require an irrelevant tread-depth
  improvement" is satisfied by never computing or requiring any
  before/after delta anywhere in `TireScoringService` — both scoring
  types always score the tire's current condition only; a REPAIR
  configuration simply need not define `kf_weights` or `requires_ka` at
  all (verified by `test_repair_config_does_not_require_ka_or_kf_by_default`).
- Decision (Phase F, BD-8): the non-overridable-invariant check compares
  a tenant-scoped draft against the **currently published platform
  default** (not the tenant's own prior version) for the same code,
  matched by `classification` label, and refuses to publish if the
  tenant version would set `is_critical_fail` from true to false or
  `eligible_for_operational_reuse` from false to true on a matching
  label. A tenant introducing a classification label the platform
  default doesn't have is unrestricted for that label (there is nothing
  to weaken). This is a structural/shape check only — it cannot and
  does not judge whether the underlying percentages are
  tire-engineering-correct; that judgment remains the publishing
  tenant admin's own responsibility and authority.
- Decision (Phase F, BD-4): finalizing a scoring result requires a
  different actor than whoever computed it (`finalize()` rejects when
  `userId === computed_by`), mirroring Phase E's receiver/approver
  separation exactly. A finalized result is immutable — there is no
  update path in `TireScoringService` after `finalized_at` is set; a
  changed assessment must be a new `calculate()` call producing a new
  row, preserving full history rather than mutating a past result.
- Decision (Phase F, BD-5): "Sell" is implemented as a new
  `TireService::sell()` action (mirroring the existing `scrap()`
  method's shape) rather than folded into Phase E's
  `approval_disposition` enum, because a sell decision is not
  necessarily preceded by a retread/repair cycle — a tire can be sold
  straight from IN_STOCK, REMOVED, or QUARANTINED. Only
  SELL_FOR_OPERATIONAL_REUSE is safety-gated (requires an existing,
  non-critical-fail, eligible scoring result); SELL_AS_RETREADABLE_CASING
  and SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL carry no such requirement, since
  neither claims the tire is fit to keep running as-is. The new terminal
  `SOLD` status is excluded from `install()`'s accepted statuses the same
  way every other non-IN_STOCK/RESERVED status already is, so a sold tire
  cannot be installed through any endpoint — verified directly, not
  assumed, by `test_sold_tire_cannot_be_installed`.

### VMS Traceability Record — Phase F

Per the task's own instruction, "the VMS audit was read-only and does
not establish write behavior or other-role permissions" — the VMS
document's Tire lifecycle screens show scored/classified results as
*displayed information* (a condition label, a history list) but cannot
by themselves prove what formula, threshold, or approval rule produced
them. The relevant VMS observation for Phase F
("Tire lifecycle screens — condition scoring / disposition workflow
observations (KA/KTS/KTN/KF-style classification, repair/retread
governance)") was already logged in the Phase D traceability record and
split across Phases E (repair/retread governance — complete) and F
(scoring/classification framework — complete, unactivated). No further
VMS-sourced field, label, or form adjustment was identified as safely
implementable within Phase F's scope this session: VMS's own displayed
classification labels and thresholds are exactly the kind of
unresolved, unsourced production values this task instructed not to
copy or invent. G-11's remaining structured tire spec fields (width/
aspect ratio/rim diameter/load-speed index) and every other prior
Phase D deferral to Phase G remain deferred to Phase G unchanged.

### VMS Traceability Record — Phase E

VMS's Tire lifecycle screens show a repair/retread history list (partner,
dates, cost) but — being a read-only Super Admin observation — do not
demonstrate the underlying approval, maker-checker, or safety-gate rules
those screens' outcomes depend on; per the task's own instruction,
"VMS read-only observations do not prove write behavior or approval
rules," none of the Phase D VMS-deferred items map to a mandatory Phase E
change beyond what the Consolidated Report's G-27/29/30/32/33/36/37
already require independently. No new VMS-sourced field or adjustment
was identified as safely implementable within Phase E scope this
session; the previously deferred items (tire scoring/classification
fields, structured tire spec fields, Rim/Vehicle master-data fields)
remain deferred to Phases F/G exactly as recorded in the Phase D
traceability record above — restated here rather than duplicated.

### VMS Traceability Record — Phase D

| VMS source section | Current OptiFleet behavior (pre-Phase-D) | Proposed adjustment | Gap ID | Status | Test evidence |
|---|---|---|---|---|---|
| Tire reference screen — "Production Date Code" field | No equivalent field on `tires` | Add nullable `manufacture_date_code` string, request-validated, never fabricated/inferred | (VMS-sourced, no G-xx) | IMPLEMENTED | `TireAssetIntegrityTest::test_manufacture_date_code_is_optional_and_stored_verbatim` |
| "Wheels Configuration" — vehicle classified into Non Trailer/Trailer/Semi-Trailer/Truck Head with a computed total wheel count | Per-category, per-named-position `wheel_configurations` table (already more granular) | None — existing model is strictly more flexible; adopting VMS's coarser enum would be a regression | (VMS-sourced, no G-xx) | DECLINED (documented decision, no code change) | N/A |
| Tire reference screen — structured spec fields (width/aspect ratio/rim diameter as discrete fields, load/speed index) | `tires.tire_size`/`pattern` are free-text strings | Structured tire spec fields | G-11 | DEFERRED to Phase F (Tire Scoring/Classification) — recorded here per task instruction, not silently expanding Phase D | N/A |
| Vehicle/Product reference screens — additional Rim and Vehicle master-data fields observed in VMS | Not present / partially present in `vehicles`/`products` | Add corresponding fields once each is confirmed against the consolidated report's Phase G scope | G-09, G-38 | DEFERRED to Phase G (Carried-Forward VMS Parity) | N/A |
| Tire lifecycle screens — condition scoring / disposition workflow observations (KA/KTS/KTN/KF-style classification, repair/retread governance) | Tire lifecycle has simple status enum + Phase D's serial/position/onboarding/rotation/replace groundwork only | Full scoring/classification framework, repair governance, maker-checker split | G-27, G-29, G-30, G-31, G-32, G-33, G-36, G-37, BD-1–BD-8 | DEFERRED to Phases E and F (already the source report's own placement; VMS observations layered under it per its §17) | N/A |
| Wheel Configuration screen — sequence/labeling conventions for axle positions | `wheel_configurations.axle_number`/`sequence`/`label` already exist and are at least as expressive as VMS's convention | None — no gap identified | — | NOT APPLICABLE | N/A |

Flagged for product decision (not resolved this session, work continued independently per task instruction): whether/when to seed
platform-default `wheel_configurations` layouts per vehicle category (needs real fleet-engineering axle/position specs, not fabricated
placeholder data) — until that decision is made, G-25's validation stays permissive for any category with no configured rows.

## Known Blockers
- None blocking Phases A–F's own acceptance criteria — every mandatory
  Phase F criterion this session could evaluate against real material
  is met, including the explicit "framework, not production formula"
  one. Phase G is not blocked, simply not started this session (large,
  multi-week scope — see roadmap in the source report §30).
- Production activation blocker (Phase F, permanent until resolved by a
  human with the relevant authority, not by further engineering): no
  TIRE_SCORING configuration exists anywhere in this system — not
  platform, not any tenant. Structured tire scoring is entirely
  unusable in production until someone with real tire-engineering/
  business authority authors and publishes actual band thresholds,
  normalized scores, classification labels, KA requirements, and (if
  wanted) KF weights, for both REPAIR and RETREAD, via the
  `POST /app/configuration/versions` + `/publish` endpoints this phase
  built. This is not an oversight — it is the deliberate consequence of
  the task's own instruction not to invent unresolved production
  scoring weights.
- Specialist/product input needed (Phase F, BD-6, does not block any
  Phase F acceptance criterion — the gap is named, not silently
  papered over): legal/policy restrictions, casing-eligibility
  criteria, and lifecycle-limit thresholds in BD-6's disposition
  precedence chain have no rule this session could encode without
  inventing one. Needed: a legal/compliance owner (legal/policy step),
  a tire-engineering source (casing eligibility, lifecycle limits).
  Vehicle/axle/operational-suitability rules need a fleet-engineering
  source — the same open item already named under Phase D's blocker
  below (wheel-position/layout expertise plausibly covers both).
- Product confirmation needed (Phase E, not resolved this session, does
  not block any Phase E acceptance criterion): the eligible partner-type
  allowlist for retread/repair work (`EXTERNAL_WORKSHOP`, `TIRE_SUPPLIER`)
  is this session's best-evidence inference from existing `partner_type`
  values, not a value enumerated by name in the material available this
  session. If the report or a later VMS reading specifies a different or
  additional type, update `TireService::ELIGIBLE_SERVICE_PARTNER_TYPES`
  (the single source of truth for this check).
- Product decision needed (Phase D, not resolved this session, does not
  block Phase E or F — explicitly reviewed and reconfirmed still open
  each phase since, see Decisions above): whether
- Product confirmation needed (Phase E, not resolved this session, does
  not block any Phase E acceptance criterion): the eligible partner-type
  allowlist for retread/repair work (`EXTERNAL_WORKSHOP`, `TIRE_SUPPLIER`)
  is this session's best-evidence inference from existing `partner_type`
  values, not a value enumerated by name in the material available this
  session. If the report or a later VMS reading specifies a different or
  additional type, update `TireService::ELIGIBLE_SERVICE_PARTNER_TYPES`
  (the single source of truth for this check).
- Product decision needed (Phase D, not resolved this session, does not
  block Phase E — explicitly reviewed and reconfirmed still open this
  phase, see Decisions above): whether
  and when to seed platform-default `wheel_configurations` layouts per
  vehicle category. No seeder exists; real axle/position specs need
  fleet-engineering input this session does not have, and CLAUDE.md
  prohibits fabricating data. Until decided, G-25's wheel-position
  validation is permissive (accepts any position) for any category with
  zero configured rows, and strict only once a tenant or platform admin
  configures at least one row for that category via the existing Wheel
  Configuration UI.
- Environment: this container has no `ext-mongodb` PHP extension and no
  `mongod` binary, so the three Mongo-backed migrations
  (`2026_09_08_000002/3`, `2026_09_08_100001` — Phase 6/7 Analytics/
  Intelligence collections) and any Phase 6/7 test suites that depend on
  them cannot run here. This is a pre-existing environment limitation,
  not introduced by this work, and does not affect Phase A (Postgres-only
  domains). Recorded as NOT RUN with reason in the final report, per
  CLAUDE.md's testing rule.

## Verification Status
- Baseline (clean `main`, before any Phase A change, 40 non-Mongo Feature
  test files): 262 passed / 878 assertions / 0 failures.
- Targeted Phase A tests (InventoryReturnClassificationTest,
  WorkOrderClosureGuardTest, WorkOrderStockIntegrationTest, plus
  regression on touched domains InventoryTest/TireTest/WorkOrderTest/
  WorkOrderExecutionTest): 48 passed / 273 assertions / 0 failures.
- Full regression post-Phase-A (same 40-file baseline set): 263 passed /
  882 assertions / 0 failures — net +1 test / +4 assertions vs. baseline
  (the new G-19 cross-tenant test added to an existing file), 0
  regressions.
- Targeted Phase B tests (UsedPartDispositionTest — new file, 9 tests —
  plus regression on WorkOrderStockIntegrationTest/
  InventoryReturnClassificationTest/WorkOrderClosureGuardTest/
  WorkflowEngineTest/WorkflowMigrationTest/InventoryTest): 63 passed /
  285 assertions / 0 failures.
- Full regression post-Phase-B (same 40-file baseline set —
  UsedPartDispositionTest.php is a new file outside this set, same as
  Phase A's two new files): 263 passed / 882 assertions / 0 failures —
  identical to the post-Phase-A regression numbers, confirming zero
  regressions from Phase B's changes.
- Targeted Phase C tests (SparePartSaleTest — new file, 8 tests — plus
  regression on UsedPartDispositionTest): 17 passed / 132 assertions /
  0 failures.
- Full regression post-Phase-C (same 40-file baseline set —
  SparePartSaleTest.php is a new file outside this set): 263 passed /
  882 assertions / 0 failures — identical to Phases A and B's regression
  numbers, confirming zero regressions from Phase C's changes.
- Targeted Phase D tests (TireAssetIntegrityTest — new file, 18 tests,
  50 assertions — plus regression on TireTest, 7 tests, 26 assertions):
  25 passed / 76 assertions / 0 failures.
- Full regression post-Phase-D: the baseline file set was expanded this
  phase to include every Feature test file added by Phases B/C/D
  (InventoryReturnClassificationTest, SparePartSaleTest,
  TireAssetIntegrityTest, UsedPartDispositionTest,
  WorkOrderClosureGuardTest) plus the full `tests/Unit` suite, since the
  previous 40-file list predated those additions — 355 passed / 1230
  assertions / 0 failures. One transient run before this showed 6
  failures in `ConfigurationAuditAndRegressionTest`/`ConfigurationCoreTest`
  with `SQLSTATE[42P01]: relation "permissions" does not exist`; this was
  a stale/partial testing-database migration state (unrelated to any
  Phase D code — those files touch Configuration, not Tire), fixed by
  running `php artisan migrate:fresh --env=testing --force` and confirmed
  by re-running the full set clean afterward. No Phase D code change was
  needed to resolve it.
- Migration verification: both new Phase D migrations
  (`2026_09_12_000007_normalize_tire_serial_number_uniqueness`,
  `2026_09_12_000008_add_legacy_onboarding_fields_to_tire_tables`) applied
  cleanly via `migrate:fresh --seed` against the dev database (Mongo
  migrations temporarily moved aside per the established environment
  workaround, then restored immediately afterward — see Known Blockers).
  The serial-number uniqueness migration's own read-only duplicate check
  found zero pre-existing case/whitespace-variant duplicates, so it did
  not need its guard-rail exception path.
- Targeted Phase E tests (TireRepairRetreadGovernanceTest — new file, 18
  tests, 91 assertions — plus regression on WorkOrderClosureGuardTest,
  updated for the new `TireService::retread()`/`receiveRetread()`
  signatures, 3 tests, 45 assertions): 21 passed / 136 assertions / 0
  failures.
- Full regression post-Phase-E (baseline file set extended once more to
  add `TireRepairRetreadGovernanceTest`): 373 passed / 1330 assertions /
  0 failures. Two transient runs before this final one showed unrelated
  failures traced to test-infrastructure causes, not Phase E code: (1) a
  genuine regression in `WorkOrderClosureGuardTest::
  test_closure_is_blocked_while_a_removed_tire_is_still_in_retread` —
  that test called `TireService::retread()`/`receiveRetread()` with their
  pre-Phase-E signatures and asserted the pre-G-36 behavior (receiving a
  retread immediately returns IN_STOCK); fixed by updating the test to
  supply an eligible partner/userId and to drive the full send → receive
  → final-inspect → approve sequence, matching the new governance flow —
  this is the one real, expected test-only fallout from Phase E's
  intentional signature/behavior changes; (2) a Postgres deadlock during
  `migrate:fresh` on the testing database, caused by running two
  `php artisan test` processes against the same testing DB concurrently
  in this session (a self-inflicted tooling collision, not a code issue)
  — resolved by re-running `migrate:fresh --env=testing --force`
  sequentially and never overlapping test runs again.
- `WorkOrderClosureGuardService::UNRESOLVED_TIRE_STATUSES` extended to
  add `REPAIR`/`QUARANTINED` (previously only `UNDER_INSPECTION`/
  `RETREAD`) — a necessary consequence of Phase E's new statuses, verified
  by the same regression run.
- Migration verification: all three new Phase E migrations
  (`2026_09_13_000001_extend_tire_lifecycle_for_repair_governance`,
  `2026_09_13_000002_add_governance_fields_to_tire_retreads`,
  `2026_09_13_000003_create_tire_repairs_table`) applied cleanly via both
  `migrate:fresh --seed` against the dev database and
  `migrate:fresh --env=testing --force` against the testing database
  (Mongo migrations temporarily moved aside per the established
  environment workaround, then restored immediately afterward).
- Static analysis (Phase E): `git diff --check` clean; `vendor/bin/pint
  --test` clean on all Phase E files (no auto-fixes needed on the final
  pass; two files needed one round of `vendor/bin/pint` auto-fix for
  import ordering, applied and re-verified clean, no logic change).
- Frontend (Phase E): `tsc -b` clean; `npm run build` (vite production
  build) succeeds; `npm run lint` (oxlint) shows only pre-existing
  warnings in files this work did not touch.
- Targeted Phase F tests (TireScoringAndSaleTest — new file, 28 tests,
  118 assertions, passing on the first full run): cover BD-3 rejection
  (missing/zero/invalid/incompatible reference, missing measurement, no
  published config, requires_ka unmet), core calculation (SPA raw/
  normalized/classification for a known band, 2-decimal rounding of a
  repeating decimal, top-band matching at/above 100%), BD-1/BD-6
  critical-fail (inspector-flagged forces ineligibility regardless of
  band, requires a reason, band-flagged forces critical even when the
  inspector says safe), BD-4 finalize maker-checker + immutability
  (self-finalize rejected, cross-actor finalize succeeds, re-finalize
  rejected), the configuration validator (gap rejected, kf_weights-on-
  unrequired-ka rejected, REPAIR config with no ka/kf requirement
  computes cleanly), BD-8 non-weakening (tenant override cannot loosen
  a platform default's matched-label safety flags), the Phase E
  integration (a critical-fail scoring result linked to a retread cycle
  blocks its RETURN_TO_SERVICE approval), BD-5 sell (rejected with no
  score / critical-fail / ineligible classification, succeeds when
  eligible, the two non-reuse sell types need no score at all, a sold
  tire can't be sold or installed again, an installed tire can't be
  sold), and permission gating on both the scoring and sell endpoints.
- Full regression post-Phase-F (baseline file set extended once more to
  add `TireScoringAndSaleTest`): 401 passed / 1448 assertions / 0
  failures — zero regressions, including the full Phase A-E suite.
- Migration verification: all four new Phase F migrations
  (`2026_09_14_000001_add_reference_tread_depth_to_products`,
  `2026_09_14_000002_add_sold_status_to_tires`,
  `2026_09_14_000003_create_tire_scoring_results_table`,
  `2026_09_14_000004_create_tire_sales_table`) applied cleanly via both
  `migrate:fresh --seed` against the dev database and
  `migrate:fresh --env=testing --force` against the testing database
  (Mongo migrations temporarily moved aside per the established
  environment workaround, then restored immediately afterward).
- Static analysis (Phase F): `git diff --check` clean; `vendor/bin/pint
  --test` clean on all Phase F files (one auto-fix round needed for a
  pre-existing file's const-declaration formatting when `TYPE_TIRE_SCORING`
  was added — applied, re-verified clean, no logic change; the new test
  file needed one auto-fix round for import ordering).
- Frontend (Phase F): `tsc -b` clean; `npm run build` (vite production
  build) succeeds; `npm run lint` (oxlint) — one new warning
  (`react(set-state-in-effect)` in `ProductDetailPage.tsx`, from
  syncing an editable field's local state from loaded product data) —
  this matches an existing, already-accepted pattern used identically
  elsewhere in this codebase (`WorkshopSchedulerPage.tsx`,
  `AnalyticsDomainPage.tsx`), not a new category of issue.
- Static analysis: `git diff --check` clean; `vendor/bin/pint --test`
  clean on all Phase A files (two migrations and three test files needed
  `vendor/bin/pint` auto-fix for import ordering/brace style — applied,
  re-verified clean, no logic change).
- Frontend: `tsc -b` clean; `npm run build` (vite production build)
  succeeds; `npm run lint` (oxlint) shows only pre-existing warnings in
  files this work did not touch.
- Migration verification: `migrate:fresh --seed` run clean on real seeded
  demo data both before and after adding Phase A's three migrations,
  including the `tenant_id` backfill on `work_order_planned_parts`
  against existing seeded rows (zero orphans, NOT NULL + FK applied
  successfully).
- Static analysis (Phase D): `git diff --check` clean; `vendor/bin/pint
  --test` clean on all Phase D files (one migration and the new test
  file needed `vendor/bin/pint` auto-fix for brace style/import
  ordering — applied, re-verified clean, no logic change).
- Frontend (Phase D): `tsc -b` clean; `npm run build` (vite production
  build) succeeds; `npm run lint` (oxlint) shows only pre-existing
  warnings in files this work did not touch.
- NOT RUN (environment limitation, pre-existing, unrelated to this
  change): Phase 6/7 Analytics/Intelligence test suites and their two
  Mongo-backed migrations — this container has no `ext-mongodb` PHP
  extension and no `mongod` binary. Full-suite `php artisan test
  --testsuite=Feature` (which includes those Mongo-dependent
  directories) could not be exercised directly for this reason; the
  40 non-Mongo Feature test files were run explicitly by name instead,
  which is a complete substitute for every Postgres-backed domain this
  phase touches or could regress.
