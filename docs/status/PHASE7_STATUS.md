# OptiFleet Phase 7 Status

Phase:
Phase 7 — Maintenance Intelligence

Status:
IN PROGRESS

Current Batch:
B — Data Readiness + Model Registry

Completed Batches:
A — Intelligence Core + Feature Pipeline:
- config/intelligence.php (schedule, job retry/backoff, feature-set
  versions, data-readiness minimums, risk/confidence thresholds, health
  score weights, RUL uncertainty band, model target catalog, alerts,
  monitoring/drift, dev-data-generator guard).
- Mongo collections + indexes: vehicle_daily_features,
  component_daily_features, tire_daily_features, intelligence_models,
  intelligence_predictions, intelligence_recommendations,
  intelligence_outcomes (migration 2026_09_08_100001).
- Postgres: maintenance_requests gets an 'INTELLIGENCE' source_type +
  nullable source_recommendation_id/source_prediction_id (migration
  2026_09_08_100002).
- VehicleFeatureExtractor, ComponentFeatureExtractor,
  TireFeatureExtractor (App\Domain\Intelligence\Extractors), all
  DatasetExtractor implementations reusing AnalyticsUpsertWriter/
  BusinessDateResolver/EtlDatasetResult verbatim — every query cut off
  at the tenant business-date end boundary (no future data).
  FeatureDatasetRegistry + FeatureRunService + RunFeatureDatasetJob
  mirror the Phase 6 DatasetRegistry/AnalyticsRunService/
  RunDatasetEtlJob shape; run tracking reuses the existing EtlRun
  collection (job_type = extractor key).
  intelligence:generate-features artisan command; scheduled in
  routes/console.php after analytics:run.
- Defect fixed: ModuleSeeder's MAINTENANCE_INTELLIGENCE depended on the
  unimplemented TELEMATICS module (would have been permanently
  ungrantable) — corrected to VEHICLE/MAINTENANCE/HISTORY/ANALYTICS per
  spec Section 59. CommercialSeeder's OPTIFLEET_INTELLIGENCE bundle
  updated to carry the new transitive dependency (ANALYTICS), and
  tests/Unit/ModuleDependencyServiceTest.php's fixture assertion updated
  to match. Full Commercial/Module/Entitlement suite re-verified green
  after the change (25 passed).

Partially Completed Work:
none currently open.

Key Architecture Decisions (carried forward from research, not yet coded):
- Intelligence artifacts live in MongoDB (feature stores, model registry,
  predictions, recommendations, outcomes) — reuse Phase 6's
  AnalyticsUpsertWriter, EtlDatasetResult, BusinessDateResolver.
- ML: interpretable pure-PHP algorithms only (logistic regression via
  gradient descent), no Python microservice — data volume doesn't
  justify it and it avoids new deploy/security surface.
- Deterministic/statistical fallback (RULE_BASED/STATISTICAL) is the
  always-available path; ML_MODEL only activates when data readiness +
  evaluation gate pass.
- Recommendation status is a simple enum + service (not the heavyweight
  Configuration-based WorkflowEngine) — this flow is system-driven, not
  tenant-customizable business approval logic.
- ModuleSeeder defect found: MAINTENANCE_INTELLIGENCE currently depends
  on TELEMATICS (unimplemented domain, would make the module ungrantable
  forever). Needs correction to VEHICLE/MAINTENANCE/HISTORY/ANALYTICS
  per spec Section 59 — planned fix, not yet applied.
- maintenance_requests.source_type is a Postgres CHECK-constraint enum
  missing an INTELLIGENCE value; needs an additive migration plus
  nullable source_recommendation_id/source_prediction_id columns for
  Section 39 linkage.

Feature-set versions:
vehicle=v1, component=v1, tire=v1 (config('intelligence.feature_set_versions')).

Model architecture:
not yet implemented (Batch C).

Validations:
- Phase 1-6 regression (baseline, before any Phase 7 change): 348/349
  passed, 1 failed (NotificationEngineTest > send notification job
  delivers...) → re-ran in isolation → PASS. Pre-existing test-order
  flake unrelated to Phase 7, not a regression introduced here.
- Batch A targeted tests (tests/Feature/Intelligence/FeaturePipelineTest.php):
  5/5 PASS — extraction fields + missing-data nulls, idempotency,
  tenant isolation, temporal-cutoff/no-leakage, entitlement gating.
- Fresh migrate:fresh --seed: PASS (after the CommercialSeeder fix above).
- Commercial/Module/Entitlement regression re-check after the
  ModuleSeeder dependency fix: 25/25 PASS.
- intelligence:generate-features --sync manual smoke test against demo
  seed data: PASS (3 datasets, idempotent re-run verified via direct
  Mongo query).
- Everything else (readiness, registry, training, inference, RUL,
  recommendations, dashboard, monitoring, drift, security/leakage
  release gate): NOT RUN — not yet implemented.

Remaining Work:
Batches B-K per the original Phase 7 specification.

Blockers:
none.

Latest Safe Commit:
(this checkpoint, Batch A). Branch based cleanly on Phase 6 checkpoint
18284bf (verified ancestor); prior HEAD was 1acd565 (CLAUDE.md bootstrap).
