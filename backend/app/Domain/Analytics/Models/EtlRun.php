<?php

namespace App\Domain\Analytics\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use MongoDB\Laravel\Eloquent\Model;

/**
 * ETL run tracking (Phase 6, Section 8). One document per
 * (tenant_id, job_type, business_date, version) — re-running the same
 * target upserts this same document (Section 9 idempotency applies to run
 * tracking too), so "latest run for X" is always a single lookup and
 * retry_count accumulates across attempts instead of growing a duplicate
 * run-history table. Full run history, when needed, lives in the
 * application log (Section 51), not as separate Mongo documents.
 *
 * tenant_id is nullable: the top-level daily orchestration run (job_type
 * = ORCHESTRATOR) has no single tenant. Deliberately does NOT use
 * BelongsToAnalyticsTenant — ETL administration (Section 50) is a
 * platform-scope concern that must see every tenant's runs; call sites
 * needing a single tenant's runs filter by tenant_id explicitly.
 */
class EtlRun extends Model
{
    use HasUuids;

    protected $connection = 'mongodb';

    protected $collection = 'analytics_etl_runs';

    public const STATUS_PENDING = 'PENDING';

    public const STATUS_RUNNING = 'RUNNING';

    public const STATUS_COMPLETED = 'COMPLETED';

    public const STATUS_PARTIAL = 'PARTIAL';

    public const STATUS_FAILED = 'FAILED';

    public const STATUS_CANCELLED = 'CANCELLED';

    protected $fillable = [
        'job_type',
        'tenant_id',
        'business_date',
        'started_at',
        'completed_at',
        'status',
        'source_count',
        'processed_count',
        'inserted_count',
        'updated_count',
        'skipped_count',
        'failed_count',
        'error_summary',
        'retry_count',
        'duration_ms',
        'version',
        'trigger',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'source_count' => 'integer',
        'processed_count' => 'integer',
        'inserted_count' => 'integer',
        'updated_count' => 'integer',
        'skipped_count' => 'integer',
        'failed_count' => 'integer',
        'retry_count' => 'integer',
        'duration_ms' => 'integer',
        // No cast for error_summary: Mongo stores PHP arrays as native BSON
        // arrays already. Laravel's "array" cast JSON-encodes to a string,
        // which is the SQL convention, not the Mongo one.
    ];
}
