<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Pipeline schedule (Section 46-47)
    |--------------------------------------------------------------------------
    |
    | Feature generation and inference run after the Phase 6 daily ETL
    | (config('analytics.schedule_time'), default 02:00 UTC) so the
    | intelligence layer always reads a settled analytical snapshot for
    | the business date, never a partially-written one.
    |
    */
    'schedule' => [
        'feature_time' => env('INTELLIGENCE_FEATURE_TIME', '03:00'),
        'predict_time' => env('INTELLIGENCE_PREDICT_TIME', '03:30'),
        'health_time' => env('INTELLIGENCE_HEALTH_TIME', '03:45'),
    ],

    'job' => [
        'max_retries' => (int) env('INTELLIGENCE_MAX_RETRIES', 3),
        'retry_backoff_seconds' => [60, 300, 900],
        'job_timeout_seconds' => (int) env('INTELLIGENCE_JOB_TIMEOUT_SECONDS', 900),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature store (Section 4-6)
    |--------------------------------------------------------------------------
    |
    | Bumping a version here changes feature_set_version on every document
    | written from that point on; old documents keep their original
    | version, so historical predictions stay reproducible against the
    | feature shape that actually produced them (Section 10).
    |
    */
    'feature_set_versions' => [
        'vehicle' => 'v1',
        'component' => 'v1',
        'tire' => 'v1',
    ],
    'feature_lookback_days' => (int) env('INTELLIGENCE_FEATURE_LOOKBACK_DAYS', 90),

    /** entity_type => Mongo collection, and entity_type => id field name in that collection. */
    'feature_collections' => [
        'vehicle' => 'vehicle_daily_features',
        'component' => 'component_daily_features',
        'tire' => 'tire_daily_features',
    ],
    'feature_entity_id_field' => [
        'vehicle' => 'vehicle_id',
        'component' => 'component_asset_id',
        'tire' => 'tire_id',
    ],
    /** Nullable-in-normal-operation numeric fields used for the missingness readiness check (Section 7). */
    'core_numeric_fields' => [
        'vehicle' => ['km_per_day', 'days_since_last_maintenance', 'km_since_last_maintenance', 'mttr_hours', 'mtbf_days'],
        'component' => ['usage_km'],
        'tire' => ['usage_km', 'cost_per_km'],
    ],
    /** Numeric feature fields actually used by the trainable logistic-regression model, in fixed order. */
    'model_feature_fields' => [
        'vehicle' => [
            'vehicle_age_days', 'current_odometer', 'km_per_day', 'overdue_maintenance_count',
            'breakdown_count_30d', 'breakdown_count_60d', 'breakdown_count_90d', 'repeat_repair_count_90d',
            'critical_inspection_finding_count_90d', 'downtime_minutes_90d', 'component_replacement_count_90d',
            'tire_replacement_count_90d', 'warranty_claim_count_90d',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Data readiness (Section 7)
    |--------------------------------------------------------------------------
    */
    'data_readiness' => [
        'min_sample_size' => (int) env('INTELLIGENCE_MIN_SAMPLE_SIZE', 30),
        'limited_sample_size' => (int) env('INTELLIGENCE_LIMITED_SAMPLE_SIZE', 10),
        'min_positive_count' => (int) env('INTELLIGENCE_MIN_POSITIVE_COUNT', 5),
        'limited_positive_count' => (int) env('INTELLIGENCE_LIMITED_POSITIVE_COUNT', 2),
        'max_missingness_ratio' => 0.4,
        'min_observation_period_days' => (int) env('INTELLIGENCE_MIN_OBSERVATION_DAYS', 30),
        'max_majority_class_ratio' => 0.98,
    ],

    /*
    |--------------------------------------------------------------------------
    | Risk / confidence bands (Section 18-19)
    |--------------------------------------------------------------------------
    |
    | Shared default thresholds against a 0-1 probability/score. Kept in
    | one place (not scattered in frontend code, per Section 18).
    |
    */
    'risk_thresholds' => ['CRITICAL' => 0.75, 'HIGH' => 0.5, 'MEDIUM' => 0.25, 'LOW' => 0.0],
    'confidence_thresholds' => ['HIGH' => 0.75, 'MEDIUM' => 0.4, 'LOW' => 0.0],

    /*
    |--------------------------------------------------------------------------
    | Vehicle intelligence health score (Section 21-22)
    |--------------------------------------------------------------------------
    |
    | Extends the Phase 6 deterministic vehicle_health_score (0-100) with
    | a predictive-risk subscore into a governed 0-100 intelligence score.
    | Weights sum to 1.0; each named subscore is itself 0-100 so the blend
    | stays interpretable and each contributes a visible amount.
    |
    */
    'health_score' => [
        'status_thresholds' => ['HEALTHY' => 90, 'GOOD' => 75, 'WATCH' => 60, 'AT_RISK' => 40, 'CRITICAL' => 0],
        'weights' => [
            'maintenance_compliance' => 0.20,
            'breakdown_history' => 0.15,
            'inspection_health' => 0.15,
            'component_reliability' => 0.15,
            'downtime' => 0.10,
            'predictive_risk' => 0.15,
            'tire_condition' => 0.10,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Component health score (Section 23)
    |--------------------------------------------------------------------------
    |
    | Deterministic point-deduction, same style as the vehicle health
    | score. usage_km_per_point defines how many km of usage deduct one
    | point (very high usage relative to a typical service life erodes
    | the score even with no recorded failures yet).
    |
    */
    'component_health' => [
        'base_score' => 100,
        'points_per_failure' => 15,
        'points_per_replacement' => 20,
        'points_per_repair' => 8,
        'usage_km_per_point' => 5000,
        'usage_km_cap_points' => 30,
        'status_thresholds' => ['HEALTHY' => 90, 'GOOD' => 75, 'WATCH' => 60, 'AT_RISK' => 40, 'CRITICAL' => 0],
    ],

    /*
    |--------------------------------------------------------------------------
    | RUL (Section 24-25)
    |--------------------------------------------------------------------------
    |
    | uncertainty_band_ratio widens a point estimate into a range when no
    | statistical stddev is available, so Phase 7 never reports fake
    | single-number precision (Section 24).
    |
    */
    'rul' => [
        'uncertainty_band_ratio' => 0.25,
    ],

    /*
    |--------------------------------------------------------------------------
    | Model targets (Section 12)
    |--------------------------------------------------------------------------
    |
    | A small, deliberately limited set. vehicle_failure_risk is the one
    | target with a real trainable ML path (logistic regression); the
    | rest are deterministic/statistical by design (Section 12: "do not
    | attempt dozens of models unnecessarily") but still flow through the
    | same registry/readiness/prediction pipeline so upgrading one to a
    | real model later needs no redesign.
    |
    | min_precision/min_recall are this target's documented acceptance
    | criteria (Section 71) — a trained model that misses either stays
    | EVALUATED, never auto-activated.
    |
    */
    'model_targets' => [
        'vehicle_failure_risk' => [
            'entity_type' => 'vehicle',
            'algorithm' => 'logistic_regression',
            'horizon_days' => 30,
            'min_precision' => 0.35,
            'min_recall' => 0.30,
        ],
        'component_failure_risk' => ['entity_type' => 'component', 'algorithm' => 'rule_based', 'horizon_days' => 30],
        'breakdown_risk' => ['entity_type' => 'vehicle', 'algorithm' => 'rule_based', 'horizon_days' => 30],
        'repeat_failure_risk' => ['entity_type' => 'vehicle', 'algorithm' => 'rule_based', 'horizon_days' => 45],
        'tire_replacement_risk' => ['entity_type' => 'tire', 'algorithm' => 'rule_based', 'horizon_days' => 30],
        'maintenance_overdue_risk' => ['entity_type' => 'vehicle', 'algorithm' => 'rule_based', 'horizon_days' => 0],
    ],

    /*
    |--------------------------------------------------------------------------
    | Deterministic/statistical fallback weights (Section 8)
    |--------------------------------------------------------------------------
    |
    | Used whenever no ACTIVE ML model exists for a target/tenant/scope.
    | A simple, fully explainable weighted linear score capped at 1.0 —
    | never presented as an ML_MODEL prediction (Section 8: "never present
    | rule-based output as ML prediction").
    |
    */
    'rule_based_risk' => [
        'vehicle_failure_risk' => [
            'overdue_maintenance_count' => 0.15,
            'breakdown_count_90d' => 0.20,
            'repeat_repair_count_90d' => 0.15,
            'critical_inspection_finding_count_90d' => 0.15,
            'component_replacement_count_90d' => 0.10,
            'tire_replacement_count_90d' => 0.05,
            'downtime_days_90d' => 0.10, // per full 24h of downtime_minutes_90d
        ],
    ],

    /** Human-readable rendering for explanation factors (Section 20, 56). */
    'factor_labels' => [
        'overdue_maintenance_count' => '{count} overdue maintenance item(s)',
        'breakdown_count_90d' => '{count} breakdown(s) in the last 90 days',
        'breakdown_count_30d' => '{count} breakdown(s) in the last 30 days',
        'repeat_repair_count_90d' => '{count} repeat repair(s) on the same component group in the last 90 days',
        'critical_inspection_finding_count_90d' => '{count} critical inspection finding(s) in the last 90 days',
        'component_replacement_count_90d' => '{count} component replacement(s) in the last 90 days',
        'tire_replacement_count_90d' => '{count} tire replacement(s) in the last 90 days',
        'downtime_days_90d' => '{count} day(s) of downtime in the last 90 days',
        'downtime_minutes_90d' => '{count} minute(s) of downtime in the last 90 days',
        'warranty_claim_count_90d' => '{count} warranty claim(s) in the last 90 days',
        'vehicle_age_days' => 'vehicle age: {count} day(s)',
        'km_per_day' => 'average usage: {count} km/day',
    ],

    'repeat_failure' => [
        'window_days' => (int) env('INTELLIGENCE_REPEAT_FAILURE_WINDOW_DAYS', 45),
        'min_occurrences' => (int) env('INTELLIGENCE_REPEAT_FAILURE_MIN_OCCURRENCES', 3),
    ],

    'anomaly' => [
        'zscore_threshold' => 2.5,
        'min_history_points' => 10,
    ],

    /*
    |--------------------------------------------------------------------------
    | Alerts (Section 35) — reuses the Phase 5 notification engine
    |--------------------------------------------------------------------------
    */
    'alerts' => [
        'min_risk_level_for_alert' => 'HIGH',
    ],

    'recommendation' => [
        'expiry_days' => (int) env('INTELLIGENCE_RECOMMENDATION_EXPIRY_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Model monitoring / drift foundation (Section 48-49)
    |--------------------------------------------------------------------------
    */
    'monitoring' => [
        'drift_window_days' => 14,
        'drift_zscore_watch' => 1.5,
        'drift_zscore_drifted' => 3.0,
        'stale_after_hours' => (int) env('INTELLIGENCE_PREDICTION_STALE_HOURS', 48),
        'expires_after_hours' => (int) env('INTELLIGENCE_PREDICTION_EXPIRES_HOURS', 168),
    ],

    /*
    |--------------------------------------------------------------------------
    | Development synthetic data generator (Section 70)
    |--------------------------------------------------------------------------
    |
    | intelligence:generate-dev-data refuses to run outside these
    | environments — it writes clearly-flagged synthetic feature/label
    | rows directly into the (already non-authoritative) Mongo feature
    | store, never into PostgreSQL, so it carries zero risk to
    | operational data even if invoked by mistake.
    |
    */
    'dev_data_generator' => [
        'enabled_environments' => ['local', 'testing'],
        'seed' => (int) env('INTELLIGENCE_DEV_DATA_SEED', 42),
    ],
];
