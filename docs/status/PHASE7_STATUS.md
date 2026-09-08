# OptiFleet Phase 7 Status

Phase:
Phase 7 — Maintenance Intelligence

Status:
COMMIT READY

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
G [6345c4f] RecommendationGenerationService + RecommendationReviewService
  (convert() -> MaintenanceRequestService only) + OutcomeFeedbackService.
  Fixed business_date bug (source_data_as_of is an exclusive boundary).
H+I [7df3648] Tenant + platform Intelligence APIs, EntityScopeResolver,
  ModelMonitoringService, DriftAssessmentService, React UI + nav.
K [547bc55] Security pass: fixed a real branch-scope leak in overview
  counts (component/tire risk counted fleet-wide instead of per-branch)
  + regression test. README updated with Phase 7 architecture/tests/limits.

Key Architecture Decisions:
- MongoDB + pure-PHP only; reuse Phase 6 ETL/upsert/business-date infra
  wholesale. No Python microservice, no new datastore.
- Deterministic fallback is the default serving path; ML (logistic
  regression, vehicle_failure_risk only) activates solely once a trained
  model clears its own acceptance gate — never automatically.
- Every insight carries insight_level + business_date explicitly.
- Recommendations: simple status graph, not the WorkflowEngine; the only
  operational write is convert() -> MaintenanceRequestService.
- Branch scope resolves via EntityScopeResolver (Vehicle join), since
  predictions carry vehicle_id, not branch_id, directly.

Feature-set versions:
vehicle=v1, component=v1, tire=v1.

Model architecture:
vehicle_failure_risk: logistic regression, horizon 30d, acceptance
precision>=0.35/recall>=0.30, deterministic fallback = weighted-linear
rule scorer. All other targets are always-deterministic by design
(Section 12: a small number of real ML targets, not dozens).

Validations (all actually executed this session):
- Full Phase 1-7 backend regression: 405/405 PASS (1261 assertions).
- Fresh migrate:fresh --seed: PASS. Frontend production build + lint: PASS.
- Manual end-to-end pipeline smoke test on real seeded demo data
  (features -> predict -> health -> diagnostics -> recommendations ->
  outcomes): PASS, and idempotent on re-run (identical Mongo counts).
- Independent trace of one real prediction against raw Postgres source
  data (0 breakdowns/0 overdue -> 0 contributing factors -> score 0,
  LOW risk -> "no significant contributing factors" explanation): PASS,
  explanation matches source data exactly.
- Docker Compose config validation: PASS. Docker image build/runtime:
  NOT RUN — sandbox network policy blocks the registry blob CDN
  (identical constraint to the Phase 6 release; Dockerfile already has
  the `mongodb` PECL extension from that phase, unchanged here).

Known Non-Blocking Issues:
- Only vehicle_failure_risk has a full ML path; a trained model on this
  project's small demo/test data correctly stays below its acceptance
  bar (safe behavior, not a defect) — the deterministic fallback is
  what actually serves it today.
- RUL is INTERVAL_BASED only (no component/tire service-interval source
  data exists yet for a true ML estimate).
- Anomaly detection is fleet-wide cross-sectional, not per-vehicle time
  series (insufficient per-vehicle history in Phase 1-5 data).
- No dedicated Intelligence CSV/XLSX export endpoint (not in the spec's
  own API list); no caching layer (same reasoning as Phase 6).

Blockers:
none.

Latest Commit:
547bc55 (pushed). Branch based cleanly on Phase 6 checkpoint 18284bf.

Next:
Phase 8 may begin ONLY upon explicit owner instruction.
