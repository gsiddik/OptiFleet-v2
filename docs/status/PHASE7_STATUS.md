# OptiFleet Phase 7 Status

Phase:
Phase 7 — Maintenance Intelligence

Status:
IN PROGRESS

Current Batch:
F — Tire + Spare Part + Demand Intelligence

Completed Batches (full detail in commit messages, not repeated here):
A — Feature pipeline: config/intelligence.php; Mongo feature/model/
prediction/recommendation/outcome collections; Vehicle/Component/
TireFeatureExtractor (temporal cutoff enforced) + registry/run-service/
job/command mirroring Phase 6 Analytics. Fixed defect: freed
MAINTENANCE_INTELLIGENCE from an ungrantable TELEMATICS dependency.
[commit dcbd1ad]

B — Model registry + readiness: IntelligenceModel lifecycle (versioned,
artifact embedded in-doc, one-ACTIVE-per-scope invariant); LabelBuilder
+ TrainingDatasetBuilder (shared readiness/training source of truth);
DataReadinessAssessmentService -> READY/LIMITED/NOT_READY.
tests/Concerns/BuildsIntelligenceHistory shared fixture. [commit a57c984]

C — Training/eval/inference: pure-PHP LogisticRegression + ModelEvaluator
(precision/recall/F1/ROC-AUC); TrainingPipelineService (readiness gate ->
TEMPORAL split, earliest 70%/latest 30%, never random -> fit -> evaluate
-> EVALUATED/FAILED; activation always separate+gated); PredictionService
(ACTIVE TENANT model -> ACTIVE GLOBAL model -> deterministic RiskScorer
fallback; risk/confidence/explanation/freshness on every prediction,
idempotent+history-preserving). Real training run on synthetic data
correctly stays below the acceptance bar (small dataset) rather than
being force-activated — confirmed intentional, not a defect. [commit f1d7556]

D — Vehicle + component health: VehicleHealthScoreService extends Phase
6's score into 7 weighted subscores incl. predictive_risk (reads that
day's failure-risk prediction); ComponentHealthScoreService (same
point-deduction family). HealthScoreRunService persists both into
intelligence_predictions (prediction_type=vehicle/component_health_score)
via the same idempotent upsert, giving risk history (Section 57) for
free. intelligence:health command, scheduled after predict. [commit ca32813]

E — RUL + repeat failure + anomaly: IntervalBasedRulService (vehicle RUL
from maintenance_schedules next-due fields already in the feature row;
tire RUL from a documented assumed expected-life-km config) — always a
range, never a fake precise number. RepeatFailureDetectionService (same
vehicle+component_group >= N repairs in a window -> DIAGNOSTIC insight).
AnomalyDetectionService: cross-sectional fleet z-score per metric
(DATA_ANOMALY for impossible values, OPERATIONAL_ANOMALY for
statistical outliers; neutral wording, no fault/fraud language).
Added insight_level (DESCRIPTIVE/DIAGNOSTIC/PREDICTIVE/PRESCRIPTIVE,
config-mapped) to every prediction doc, retrofitted onto Batch C/D
writers too. DiagnosticsRunService + intelligence:diagnostics command,
scheduled after health. [commit pending]

Partially Completed Work:
none currently open.

Key Architecture Decisions:
- Intelligence artifacts live in MongoDB; reuse Phase 6 Analytics infra
  wholesale (upsert writer, business-date resolver, run tracking).
- ML: interpretable pure-PHP only, no Python microservice. Artifact
  embedded in the registry doc, never a filesystem path.
- Deterministic fallback always serves absent an ACTIVE model; ACTIVE
  requires the model's own acceptance gate, never automatic.
- Every insight carries insight_level (DESCRIPTIVE/DIAGNOSTIC/
  PREDICTIVE/PRESCRIPTIVE, Section 3), config-mapped per prediction_type.
- Recommendation status (Batch G): planned as a simple enum + service,
  not the heavyweight Configuration WorkflowEngine.

Feature-set versions:
vehicle=v1, component=v1, tire=v1.

Model architecture:
vehicle_failure_risk: logistic regression (pure PHP, gradient descent),
horizon 30d, acceptance precision>=0.35/recall>=0.30, deterministic
fallback = weighted-linear rule scorer. Health/RUL/repeat-failure/
anomaly are always-deterministic by design (Section 12: a small number
of real ML targets, not dozens). tire/component/breakdown/maintenance-
overdue *_risk targets still rule_based-only (Batch F) — not yet built.

Validations:
- Phase 1-6 regression (baseline): 348/349, 1 pre-existing test-order
  flake (NotificationEngineTest) confirmed PASS in isolation — unrelated
  to Phase 7.
- Intelligence test suite (Batches A-E): 30/30 PASS — feature pipeline
  (5), model registry (5), data readiness (4), training pipeline (4),
  prediction pipeline (5), health score (3), diagnostics/RUL/repeat-
  failure/anomaly (4).
- Fresh migrate:fresh --seed: PASS. Commercial/Module/Entitlement
  regression after the ModuleSeeder fix: 25/25 PASS.
- intelligence:generate-features --sync manual smoke test: PASS.
- Everything else (tire/spare-part/demand intelligence, recommendations,
  dashboard, monitoring, drift, security/leakage release gate): NOT RUN.

Remaining Work:
Batches F-K per the original Phase 7 specification.

Blockers:
none.

Latest Safe Commit:
ca32813 (Batch D, pushed). Batch E not yet committed as of this writing.
Branch based cleanly on Phase 6 checkpoint 18284bf.
