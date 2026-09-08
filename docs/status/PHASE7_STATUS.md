# OptiFleet Phase 7 Status

Phase:
Phase 7 — Maintenance Intelligence

Status:
IN PROGRESS

Current Batch:
E — RUL + Repeat Failure + Anomaly

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
free. intelligence:health command, scheduled after predict.

Partially Completed Work:
none currently open.

Key Architecture Decisions:
- Intelligence artifacts live in MongoDB; reuse Phase 6 Analytics infra
  wholesale (upsert writer, business-date resolver, run tracking).
- ML: interpretable pure-PHP only (logistic regression), no Python
  microservice — data volume doesn't justify the extra deploy/security
  surface. Model artifact is embedded in the registry doc, never a
  filesystem path (closes path-injection risk by construction).
- Deterministic fallback always serves when no ACTIVE model exists;
  ACTIVE requires passing the model's own documented acceptance gate —
  never automatic on training completion.
- Recommendation status (Batch G): planned as a simple enum + service,
  not the heavyweight Configuration WorkflowEngine.

Feature-set versions:
vehicle=v1, component=v1, tire=v1.

Model architecture:
vehicle_failure_risk: logistic regression (pure PHP, gradient descent),
horizon 30d, acceptance precision>=0.35/recall>=0.30, deterministic
fallback = weighted-linear rule scorer. Vehicle/component health scores
are always-deterministic (not ML targets). All other risk targets
(component/breakdown/repeat-failure/tire-replacement/maintenance-overdue)
are rule_based by design (Batch E-F) — not yet built.

Validations:
- Phase 1-6 regression (baseline): 348/349, 1 pre-existing test-order
  flake (NotificationEngineTest) confirmed PASS in isolation — unrelated
  to Phase 7.
- Intelligence test suite (Batches A-D): 26/26 PASS — feature pipeline
  (5), model registry (5), data readiness (4), training pipeline (4),
  prediction pipeline (5), health score (3, incl. component health from
  a real component_assets/installations fixture).
- Fresh migrate:fresh --seed: PASS. Commercial/Module/Entitlement
  regression after the ModuleSeeder fix: 25/25 PASS.
- intelligence:generate-features --sync manual smoke test: PASS.
- Everything else (RUL, repeat failure, anomaly, tire/component/
  spare-part/demand intelligence, recommendations, dashboard, monitoring,
  drift, security/leakage release gate): NOT RUN.

Remaining Work:
Batches E-K per the original Phase 7 specification.

Blockers:
none.

Latest Safe Commit:
f1d7556 (Batch C, pushed). Batch D not yet committed as of this writing.
Branch based cleanly on Phase 6 checkpoint 18284bf.
