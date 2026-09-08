# OptiFleet Phase 7 Status

Phase:
Phase 7 — Maintenance Intelligence

Status:
IN PROGRESS

Current Batch:
D — Vehicle + Component Health / Risk

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

C — Training / Evaluation / Inference: LogisticRegression (pure PHP
gradient descent, standardized features, deterministic/reproducible,
contributions() for explainability) + ModelEvaluator (precision/recall/
F1/ROC-AUC, exact Mann-Whitney). TrainingPipelineService: readiness gate
-> TEMPORAL split (earliest 70% dates train, latest 30% test, never
random) -> fit -> evaluate -> register EVALUATED/FAILED; activation
stays a separate explicit step (CLI --activate or future API), always
through ModelRegistryService's acceptance-criteria gate. TrainModelJob
(queued, per tenant+target). PredictionService: ACTIVE TENANT model ->
ACTIVE GLOBAL model -> deterministic RiskScorer fallback, in that order;
RiskLevelCalculator + ConfidenceCalculator (confidence penalized for
non-ML source, LIMITED training readiness, and feature missingness —
kept strictly separate from risk); DeterministicVehicleFailureRiskScorer
(weighted linear, config('intelligence.rule_based_risk')); predictions
upserted idempotently per (tenant, entity, type, horizon,
source_data_as_of) via the same AnalyticsUpsertWriter, so distinct
business dates accumulate real history while re-runs don't duplicate.
PredictionRunService/RunPredictionJob/intelligence:predict mirror the
feature-pipeline orchestration; scheduled after generate-features.
Manually verified real training run on synthetic history reaches
EVALUATED with computed metrics (precision/recall/roc_auc) that do NOT
clear the acceptance bar on this small a dataset — correct, safe
behavior (Section 71), not a defect; deterministic fallback is what
actually serves in that case, confirmed by test.

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
fallback = weighted-linear rule scorer. All other targets
(component/breakdown/repeat-failure/tire-replacement/maintenance-overdue
risk) are rule_based/deterministic by design (Batch D-F) — not yet built.

Validations:
- Phase 1-6 regression (baseline): 348/349, 1 pre-existing test-order
  flake (NotificationEngineTest) confirmed PASS in isolation — unrelated
  to Phase 7.
- Intelligence test suite so far (Batches A-C): 23/23 PASS — feature
  pipeline (5), model registry (5), data readiness (4), training
  pipeline (4), prediction pipeline (5, incl. explanation traced back
  to actual feature values and ML-vs-RULE_BASED source labeling).
- Fresh migrate:fresh --seed: PASS. Commercial/Module/Entitlement
  regression after the ModuleSeeder fix: 25/25 PASS.
- intelligence:generate-features --sync manual smoke test: PASS.
- Everything else (health/risk beyond vehicle_failure_risk, RUL, repeat
  failure, anomaly, tire/component/spare-part/demand intelligence,
  recommendations, dashboard, monitoring, drift, security/leakage
  release gate): NOT RUN.

Remaining Work:
Batches D-K per the original Phase 7 specification.

Blockers:
none.

Latest Safe Commit:
a57c984 (Batch B, pushed). Batch C not yet committed as of this writing.
Branch based cleanly on Phase 6 checkpoint 18284bf.
