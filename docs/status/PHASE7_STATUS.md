# OptiFleet Phase 7 Status

Phase:
Phase 7 — Maintenance Intelligence

Status:
IN PROGRESS

Current Batch:
G — Prescriptive Recommendation + Feedback

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
  AnomalyDetectionService (DATA_ vs OPERATIONAL_ANOMALY); insight_level
  (DESCRIPTIVE/DIAGNOSTIC/PREDICTIVE/PRESCRIPTIVE) added to every doc.
F [pending] SparePartIntelligenceService (top consumed parts + trend,
  per-vehicle part usage) + InventoryDemandForecastService (moving-average
  forecast, shortage risk, suggested reorder qty — never creates a PO,
  verified by test) + TireIntelligenceService (product performance,
  abnormal-wear z-score). All computed on demand from Postgres/feature
  store, no new collection (consumption volume doesn't need a daily
  snapshot the way per-vehicle features do).

Partially Completed Work:
none currently open.

Key Architecture Decisions:
- Intelligence artifacts live in MongoDB; reuse Phase 6 Analytics infra
  wholesale (upsert writer, business-date resolver, run tracking).
- ML: interpretable pure-PHP only, no Python microservice. Artifact
  embedded in the registry doc, never a filesystem path.
- Deterministic fallback always serves absent an ACTIVE model; ACTIVE
  requires the model's own acceptance gate, never automatic.
- Every insight carries insight_level (Section 3), config-mapped.
- Recommendation status (Batch G): planned as a simple enum + service,
  not the heavyweight Configuration WorkflowEngine.

Feature-set versions:
vehicle=v1, component=v1, tire=v1.

Model architecture:
vehicle_failure_risk: logistic regression, horizon 30d, acceptance
precision>=0.35/recall>=0.30, deterministic fallback = weighted-linear
rule scorer. Health/RUL/repeat-failure/anomaly/tire/spare-part/demand
are always-deterministic by design (Section 12: a small number of real
ML targets, not dozens). component/breakdown/maintenance-overdue
*_risk targets still unbuilt (not required beyond vehicle_failure_risk
for the Definition of Done's named list — revisit only if audit finds
a gap).

Validations:
- Phase 1-6 regression (baseline): 348/349, 1 pre-existing test-order
  flake (NotificationEngineTest) confirmed PASS in isolation.
- Intelligence test suite (Batches A-F): 33/33 PASS.
- Fresh migrate:fresh --seed: PASS. Commercial/Module/Entitlement
  regression after the ModuleSeeder fix: 25/25 PASS.
- intelligence:generate-features --sync manual smoke test: PASS.
- Everything else (recommendations, dashboard/APIs, monitoring, drift,
  security/leakage release gate): NOT RUN.

Remaining Work:
Batches G-K per the original Phase 7 specification.

Blockers:
none.

Latest Safe Commit:
b023dbd (Batch E, pushed). Batch F not yet committed as of this writing.
Branch based cleanly on Phase 6 checkpoint 18284bf.
