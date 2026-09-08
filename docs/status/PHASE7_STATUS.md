# OptiFleet Phase 7 Status

Phase:
Phase 7 — Maintenance Intelligence

Status:
IN PROGRESS

Current Batch:
H — Dashboard + APIs

Completed Batches (one line each; full detail in commit messages):
A [dcbd1ad] Feature pipeline: config/intelligence.php; Mongo feature/
  model/prediction/recommendation/outcome collections; Vehicle/Component/
  TireFeatureExtractor (temporal cutoff enforced); fixed ungrantable
  MAINTENANCE_INTELLIGENCE->TELEMATICS dependency defect.
B [a57c984] Model registry (versioned, artifact in-doc, one-ACTIVE
  invariant) + LabelBuilder/TrainingDatasetBuilder + DataReadinessAssessmentService.
C [f1d7556] Pure-PHP LogisticRegression+ModelEvaluator; TrainingPipelineService
  (TEMPORAL split, gated activation); PredictionService (ACTIVE model ->
  deterministic fallback, idempotent+history-preserving).
D [ca32813] VehicleHealthScoreService (7 subscores incl. predictive_risk)
  + ComponentHealthScoreService; HealthScoreRunService persists both.
E [b023dbd] IntervalBasedRulService (always a range); RepeatFailureDetectionService;
  AnomalyDetectionService (DATA_ vs OPERATIONAL_ANOMALY); insight_level added.
F [78a88ed] SparePartIntelligenceService + InventoryDemandForecastService
  (never creates a PO) + TireIntelligenceService — computed on demand.
G [pending] RecommendationGenerationService (config-driven prediction ->
  recommendation_type+priority rules, idempotent per prediction_id, fires
  Section 35 alerts via existing NotificationDispatchService — 8 event
  codes registered in NotificationEventCatalog + 2 default seeded rules);
  RecommendationReviewService (NEW->REVIEWED->ACCEPTED->CONVERTED/REJECTED/
  EXPIRED, audited via AuditService; convert() creates a Maintenance
  Request exclusively through MaintenanceRequestService, source_type=
  INTELLIGENCE, linked via source_recommendation_id/source_prediction_id);
  OutcomeFeedbackService reuses the Batch B LabelBuilder to auto-evaluate
  matured predictions against ground truth. Fixed a cross-batch bug found
  while testing: source_data_as_of is the *exclusive* end-of-day boundary
  (next calendar day), so string-prefix matching it against a business
  date silently matched nothing — added an explicit business_date field
  to every prediction doc (Batches C/D/E all patched) and fixed the two
  broken lookups (HealthScoreRunService, RecommendationGenerationService).

Partially Completed Work:
none currently open.

Key Architecture Decisions:
- Intelligence artifacts live in MongoDB; reuse Phase 6 Analytics infra
  wholesale (upsert writer, business-date resolver, run tracking).
- ML: interpretable pure-PHP only, no Python microservice. Artifact
  embedded in the registry doc, never a filesystem path.
- Deterministic fallback always serves absent an ACTIVE model; ACTIVE
  requires the model's own acceptance gate, never automatic.
- Every insight carries insight_level (Section 3) and business_date
  (explicit, never derived from source_data_as_of string-matching).
- Recommendation status: simple enum + service, not the heavyweight
  Configuration WorkflowEngine — conversion is the only place a
  recommendation touches an operational table, exclusively through
  MaintenanceRequestService (Section 2: no direct AI control).

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
- Intelligence test suite (Batches A-G): 41/41 PASS.
- Regression re-check after Batch G's MaintenanceRequest/notification
  changes: MaintenanceRequestAndBreakdownTest + WorkOrderTest +
  NotificationEngineTest = 28/28 PASS.
- Fresh migrate:fresh --seed: PASS. Commercial/Module/Entitlement
  regression after the ModuleSeeder fix: 25/25 PASS.
- Everything else (dashboard/APIs, monitoring, drift, security/leakage
  release gate): NOT RUN.

Remaining Work:
Batches H-K per the original Phase 7 specification.

Blockers:
none.

Latest Safe Commit:
78a88ed (Batch F, pushed). Batch G not yet committed as of this writing.
Branch based cleanly on Phase 6 checkpoint 18284bf.
