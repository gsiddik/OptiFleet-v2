# OptiFleet Phase 7 Status

Phase:
Phase 7 — Maintenance Intelligence

Status:
IN PROGRESS

Current Batch:
J — Tests + Full Regression

Completed Batches (one line each; full detail in commit messages):
A [dcbd1ad] Feature pipeline (Vehicle/Component/TireFeatureExtractor,
  temporal cutoff); fixed ungrantable MAINTENANCE_INTELLIGENCE->TELEMATICS defect.
B [a57c984] Model registry (versioned, one-ACTIVE invariant) +
  LabelBuilder/TrainingDatasetBuilder + DataReadinessAssessmentService.
C [f1d7556] Pure-PHP LogisticRegression+ModelEvaluator; TrainingPipelineService
  (TEMPORAL split, gated activation); PredictionService (ACTIVE model ->
  deterministic fallback, idempotent+history-preserving).
D [ca32813] VehicleHealthScoreService (7 subscores) + ComponentHealthScoreService.
E [b023dbd] IntervalBasedRulService + RepeatFailureDetectionService +
  AnomalyDetectionService; insight_level added to every doc.
F [78a88ed] SparePartIntelligenceService + InventoryDemandForecastService
  (never creates a PO) + TireIntelligenceService.
G [6345c4f] RecommendationGenerationService (config-driven rules, fires
  Section 35 alerts) + RecommendationReviewService (convert() creates a
  Maintenance Request exclusively through MaintenanceRequestService) +
  OutcomeFeedbackService. Fixed cross-batch bug: source_data_as_of is
  the exclusive end-of-day boundary, not business_date — added an
  explicit business_date field to every doc, fixed two broken lookups.
H+I [pending] Tenant APIs (overview/vehicles/components/tires/inventory/
  predictions/recommendations, all data-scope + permission enforced via
  new EntityScopeResolver); platform APIs (models/training/monitoring/
  drift, all audited); intelligence.* permissions seeded (10 tenant + 7
  platform). ModelMonitoringService (prediction volume, confidence,
  precision/recall from matured outcomes) + DriftAssessmentService
  (fleet feature-distribution z-score, STABLE/WATCH/DRIFTED, tenant
  isolated). React UI: Overview/Vehicles(list+detail)/Components/Tires/
  Recommendations pages + nav group, module+permission gated like
  Analytics. Frontend production build + lint: clean. Found/fixed:
  EntityScopeResolver typed against the wrong User class (App\Models\User,
  not App\Domain\Identity\Models\User — matches DataScopeService's own
  signature); FerretDB (sandbox test double) doesn't implement Mongo's
  $addToSet aggregation accumulator — switched the one distinct-count
  query to a portable PHP-side unique(), which is also just as correct
  against real MongoDB.

Partially Completed Work:
none currently open.

Key Architecture Decisions:
- Intelligence artifacts live in MongoDB; reuse Phase 6 Analytics infra
  wholesale (upsert writer, business-date resolver, run tracking).
- ML: interpretable pure-PHP only, no Python microservice. Artifact
  embedded in the registry doc, never a filesystem path.
- Deterministic fallback always serves absent an ACTIVE model; ACTIVE
  requires the model's own acceptance gate, never automatic.
- Every insight carries insight_level (Section 3) and business_date.
- Recommendation status: simple enum + service, not the heavyweight
  Configuration WorkflowEngine — conversion is the only place a
  recommendation touches an operational table, exclusively through
  MaintenanceRequestService (Section 2: no direct AI control).
- Branch data-scope on intelligence docs resolves through Vehicle's own
  branch_id (EntityScopeResolver), since predictions carry vehicle_id,
  not branch_id, directly.

Feature-set versions:
vehicle=v1, component=v1, tire=v1.

Model architecture:
vehicle_failure_risk: logistic regression, horizon 30d, acceptance
precision>=0.35/recall>=0.30, deterministic fallback = weighted-linear
rule scorer. Health/RUL/repeat-failure/anomaly/tire/spare-part/demand/
recommendations are always-deterministic by design (Section 12).

Validations:
- Phase 1-6 regression (baseline): 348/349, 1 pre-existing test-order
  flake (NotificationEngineTest) confirmed PASS in isolation.
- Intelligence test suite (Batches A-I): 55/55 PASS across 10 test files
  (feature pipeline, model registry, data readiness, training, prediction,
  health score, diagnostics/RUL/repeat-failure/anomaly, inventory/tire,
  recommendation, outcome feedback, tenant API, admin API, monitoring/drift).
- Regression re-check after Batch G's shared-model changes: 28/28 PASS.
- Fresh migrate:fresh --seed: PASS. Commercial/Module/Entitlement
  regression after the ModuleSeeder fix: 25/25 PASS.
- Frontend production build + lint: PASS.
- Everything else (full Phase 1-7 regression, security/leakage review,
  Docker, release gate): NOT RUN.

Remaining Work:
Batches J-K per the original Phase 7 specification.

Blockers:
none.

Latest Safe Commit:
6345c4f (Batch G, pushed). Batches H+I not yet committed as of this writing.
Branch based cleanly on Phase 6 checkpoint 18284bf.
