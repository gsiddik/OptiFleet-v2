<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Phase 6 Section 8-9: analytics_etl_runs tracks every ETL execution.
 * Guarded with hasCollection() because `migrate:fresh` only resets
 * PostgreSQL — the Mongo collection (and its data) survives a Postgres
 * reset, so this migration must be safe to run again without erroring.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('mongodb');

        if (! $schema->hasCollection('analytics_etl_runs')) {
            $schema->create('analytics_etl_runs');
        }

        $schema->table('analytics_etl_runs', function (Blueprint $collection) {
            // Idempotency key for the run-tracking document itself
            // (Section 9): re-running the same target upserts in place.
            $collection->unique(
                ['tenant_id', 'job_type', 'business_date', 'version'],
                'uniq_etl_run_target'
            );

            // ETL administration (Section 50): "current run" / "failed
            // runs" / recent-history queries.
            $collection->index(['status', 'started_at'], 'idx_etl_run_status_started');
            $collection->index(['job_type', 'business_date'], 'idx_etl_run_job_date');
        });
    }

    public function down(): void
    {
        Schema::connection('mongodb')->dropIfExists('analytics_etl_runs');
    }
};
