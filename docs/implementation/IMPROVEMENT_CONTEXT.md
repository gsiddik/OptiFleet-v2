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
| E — Tire Repair/Retread Governance | G-27, G-29, G-32, G-36, G-30, G-33, G-37 | NOT STARTED | — | — | REPAIR disposition, locked cycle numbering, partner-type constraint, maker-checker split, final-inspection gate | Depends on Phase D |
| F — Tire Scoring/Classification | G-31, G-11, BD-1–BD-8 | NOT STARTED | — | — | Full versioned scoring/config framework | Depends on Phases D and E |
| G — Carried-Forward VMS Parity | G-01–G-09, G-11–G-13, G-38–G-43 | NOT STARTED | — | — | Schedule→WO, Cost Estimation, Maintenance Result, tiered PO approval, Workshop Partner cycle, remaining master-data UI | Independent; not started this session |

## Current Work
- Active phase: D (implemented and validated this session; Phases A, B,
  and C already pushed to `origin/Improvement`).
- Active batch: none in progress — Phase D is complete pending final
  regression confirmation and the phase-boundary push.
- Files/modules in progress: none.
- Immediate next action for the following session: re-read this file and
  Git history to confirm Phase D's push landed on `origin/Improvement`,
  then begin Phase E (Tire Repair/Retread Governance, G-27/G-29/G-32/
  G-36/G-30/G-33/G-37), which depends on Phase D's serial/position/
  rotation/replace groundwork now being in place.

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
- None blocking Phases A–D. Phases E–G are not blocked, simply not
  started this session (large, multi-week scope — see roadmap in the
  source report §30).
- Product decision needed (Phase D, not resolved this session): whether
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
