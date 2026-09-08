<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Business timezone strategy (Phase 6, Sections 7 & 56)
    |--------------------------------------------------------------------------
    |
    | Storage stays UTC everywhere, matching the existing APP_TIMEZONE=UTC
    | convention for PostgreSQL created_at/updated_at columns and Mongo
    | dates. A "business_date" (the day an ETL run/snapshot belongs to) is
    | never the UTC calendar date — it is the calendar date obtained by
    | converting the relevant UTC instant into the *tenant's* timezone
    | (tenants.timezone, added in this phase, default 'UTC'). This value
    | below is only the fallback used when a tenant has no timezone set.
    |
    | The scheduler itself (routes/console.php) always fires in server/UTC
    | time; `analytics:run` then computes each tenant's own business_date
    | independently via App\Domain\Analytics\Support\BusinessDateResolver.
    | This is why the daily job is scheduled late (see schedule_time) —
    | late enough in UTC that same-day operational data has settled for
    | the great majority of real-world tenant timezones (UTC-12..UTC+14).
    |
    */
    'default_timezone' => env('ANALYTICS_DEFAULT_TIMEZONE', 'UTC'),

    /*
    |--------------------------------------------------------------------------
    | Daily ETL schedule
    |--------------------------------------------------------------------------
    |
    | Time of day (server/UTC, "H:i") the automated daily ETL fires, wired
    | into routes/console.php. Configurable via env rather than hardcoded,
    | per Section 7.
    |
    */
    'schedule_time' => env('ANALYTICS_SCHEDULE_TIME', '02:00'),

    /*
    |--------------------------------------------------------------------------
    | ETL run control (Sections 13-15)
    |--------------------------------------------------------------------------
    |
    | max_retries / retry_backoff_seconds drive the queued dataset jobs
    | (App\Domain\Analytics\Jobs\RunDatasetEtlJob) — Laravel's own
    | ShouldQueue tries/backoff, not a custom loop, so infra-level failures
    | (Mongo temporarily down) are retried with the framework's guarantees
    | rather than an ad-hoc while-loop.
    |
    | incremental_lookback_days: extraction for "incremental" datasets
    | always re-pulls the last N days (not just the target business_date)
    | to absorb late-arriving corrections (Section 11) without a full
    | historical scan. See each Extractor's watermark documentation.
    |
    */
    'max_retries' => (int) env('ANALYTICS_ETL_MAX_RETRIES', 3),
    'retry_backoff_seconds' => [60, 300, 900],
    'job_timeout_seconds' => (int) env('ANALYTICS_ETL_TIMEOUT_SECONDS', 600),
    'incremental_lookback_days' => (int) env('ANALYTICS_INCREMENTAL_LOOKBACK_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Vehicle Health Foundation (Section 19)
    |--------------------------------------------------------------------------
    |
    | Deterministic, explainable point-deduction scoring — NOT machine
    | learning, NOT a predictive probability. Starting score is 100;
    | each contributing factor subtracts a configured, documented amount
    | (floored at 0). Changing these weights changes the score for every
    | tenant uniformly and is the only place the formula is tuned.
    |
    */
    'vehicle_health' => [
        'base_score' => 100,
        'weights' => [
            'overdue_maintenance' => 15,   // per overdue scheduled/policy item
            'open_critical_finding' => 20, // per open inspection finding marked critical
            'recent_breakdown' => 10,      // per breakdown in the lookback window
            'repeat_repair' => 8,          // per repeat repair (same component group) in window
            'downtime_hours' => 1,         // per full hour of downtime in window (capped)
            'downtime_hours_cap' => 20,    // max points deducted from downtime alone
            'component_failure' => 10,     // per failed component in window
            'tire_condition' => 5,         // per tire flagged damaged/worn in window
        ],
        'lookback_days' => (int) env('ANALYTICS_HEALTH_LOOKBACK_DAYS', 90),
        'status_thresholds' => [
            // score >= threshold => status
            'GOOD' => 80,
            'FAIR' => 60,
            'POOR' => 40,
            'CRITICAL' => 0,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | MTTR / MTBF qualifying statuses (Sections 24-25)
    |--------------------------------------------------------------------------
    |
    | MTTR only counts Work Orders that reached COMPLETED or CLOSED with a
    | recorded started_at/completed_at pair — REJECTED/CANCELLED are
    | excluded (Section 24: "do not calculate from incomplete/cancelled
    | jobs"). MTBF uses Breakdown records with status RESOLVED.
    |
    */
    'mttr_qualifying_statuses' => ['COMPLETED', 'CLOSED'],
    'mtbf_qualifying_breakdown_statuses' => ['RESOLVED'],

    /*
    |--------------------------------------------------------------------------
    | Mechanic utilization assumption (Section 28)
    |--------------------------------------------------------------------------
    |
    | Phase 1-5 has no shift/roster table recording a mechanic's actual
    | scheduled working minutes per day, so utilization is computed
    | against this configurable standard shift length (documented
    | assumption, not measured availability).
    |
    */
    'mechanic_standard_shift_minutes' => (int) env('ANALYTICS_MECHANIC_SHIFT_MINUTES', 480),

    /*
    |--------------------------------------------------------------------------
    | Data retention (Section 52)
    |--------------------------------------------------------------------------
    |
    | Daily analytical snapshots: kept indefinitely by default (null = no
    | automatic deletion) — they are small, one-document-per-dimension-per-
    | day, and long retention is explicitly expected for trend analysis.
    | ETL run log: pruned after N days by `analytics:prune-etl-runs` (an
    | explicit, documented, opt-in command — never automatic silent
    | deletion of the analytical data itself).
    |
    */
    'retention' => [
        'daily_snapshot_days' => env('ANALYTICS_SNAPSHOT_RETENTION_DAYS') ? (int) env('ANALYTICS_SNAPSHOT_RETENTION_DAYS') : null,
        'etl_run_log_days' => (int) env('ANALYTICS_ETL_LOG_RETENTION_DAYS', 180),
    ],

    /*
    |--------------------------------------------------------------------------
    | Export foundation (Section 47)
    |--------------------------------------------------------------------------
    */
    'export' => [
        'max_rows' => (int) env('ANALYTICS_EXPORT_MAX_ROWS', 50000),
        'chunk_size' => (int) env('ANALYTICS_EXPORT_CHUNK_SIZE', 1000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation tolerance (Section 61)
    |--------------------------------------------------------------------------
    |
    | A count/amount mismatch between PostgreSQL and the Mongo projection
    | above this tolerance is reported as material by
    | AnalyticsReconciliationService.
    |
    */
    'reconciliation' => [
        'count_tolerance' => (int) env('ANALYTICS_RECONCILE_COUNT_TOLERANCE', 0),
        'amount_tolerance' => (float) env('ANALYTICS_RECONCILE_AMOUNT_TOLERANCE', 0.01),
    ],
];
