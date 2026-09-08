# OptiFleet Phase 7 Status

Phase:
Phase 7 — Maintenance Intelligence

Status:
IN PROGRESS

Current Batch:
C — Training / Evaluation / Inference

Completed Batches:
A — Intelligence Core + Feature Pipeline: config/intelligence.php;
Mongo collections (vehicle/component/tire_daily_features,
intelligence_models/predictions/recommendations/outcomes, migration
2026_09_08_100001); maintenance_requests +INTELLIGENCE source_type +
source_recommendation_id/source_prediction_id (2026_09_08_100002);
Vehicle/Component/TireFeatureExtractor (DatasetExtractor, reuse
AnalyticsUpsertWriter/BusinessDateResolver, temporal cutoff enforced);
FeatureDatasetRegistry/FeatureRunService/RunFeatureDatasetJob mirror
Phase 6's Analytics equivalents; intelligence:generate-features command,
scheduled after analytics:run. Fixed defect: MAINTENANCE_INTELLIGENCE
depended on unimplemented TELEMATICS (ungrantable) -> corrected to
VEHICLE/MAINTENANCE/HISTORY/ANALYTICS (spec Section 59); CommercialSeeder
bundle + ModuleDependencyServiceTest updated to match.

B — Data Readiness + Model Registry: IntelligenceModel (DRAFT->TRAINING
->EVALUATED->ACTIVE/RETIRED/FAILED, versioned per model_code/scope/
tenant, artifact embedded in-doc — no path-based load, closes artifact-
injection risk by construction); ModelRegistryService (activate() gates
on acceptance_criteria, atomically retires prior ACTIVE — one active
version invariant); LabelBuilder + VehicleFailureLabelBuilder (null,
not false, when horizon unelapsed); TrainingDatasetBuilder (shared by
readiness + training so they can't disagree); DataReadinessAssessmentService
-> READY/LIMITED/NOT_READY. tests/Concerns/BuildsIntelligenceHistory:
shared small fixture (8 vehicles x 20 days, real extractor) for fast tests.

Partially Completed Work:
none currently open.

Key Architecture Decisions:
- Intelligence artifacts live in MongoDB; reuse Phase 6 Analytics
  infra wholesale (upsert writer, business-date resolver, run tracking).
- ML: interpretable pure-PHP only (logistic regression), no Python
  microservice — data volume doesn't justify the extra deploy/security
  surface; config/algorithm fields keep the door open later.
- Deterministic/statistical fallback is always-available; ML_MODEL only
  serves once data readiness + the model's own acceptance gate pass.
- Recommendation status: simple enum + service, not the heavyweight
  Configuration WorkflowEngine (this flow is system-driven, not
  tenant-customizable business approval logic).

Feature-set versions:
vehicle=v1, component=v1, tire=v1.

Model architecture:
vehicle_failure_risk: logistic regression (pure PHP, gradient descent),
horizon 30d, acceptance precision>=0.35/recall>=0.30. All other targets
(component/breakdown/repeat-failure/tire-replacement/maintenance-overdue
risk) are rule_based/deterministic by design (Batch D-F) — not yet built.

Validations:
- Phase 1-6 regression (baseline): 348/349, 1 pre-existing test-order
  flake (NotificationEngineTest) confirmed PASS in isolation — unrelated
  to Phase 7.
- Batch A tests (FeaturePipelineTest): 5/5 PASS (fields+nulls,
  idempotency, tenant isolation, temporal-cutoff/no-leakage, entitlement).
- Batch B tests (ModelRegistryTest, DataReadinessTest): 9/9 PASS
  (versioning, acceptance gate, one-active invariant, per-tenant
  training isolation, unelapsed-horizon exclusion).
- Fresh migrate:fresh --seed: PASS. Commercial/Module/Entitlement
  regression after the ModuleSeeder fix: 25/25 PASS.
- intelligence:generate-features --sync manual smoke test: PASS.
- Everything else (training, inference, health/risk, RUL, recommendations,
  dashboard, monitoring, drift, security/leakage release gate): NOT RUN.

Remaining Work:
Batches C-K per the original Phase 7 specification.

Blockers:
none.

Latest Safe Commit:
dcbd1ad (Batch A checkpoint, pushed). Batch B not yet committed as of
this writing. Branch based cleanly on Phase 6 checkpoint 18284bf.
