<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Models\EtlRun;
use App\Domain\Analytics\Support\EtlDatasetResult;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Phase 6 Section 8-9: tracks every ETL execution, idempotently. start()
 * upserts the single run document for (tenant, job_type, business_date,
 * version) — re-running the same target reuses that document rather than
 * creating a duplicate, and bumps retry_count when the previous attempt
 * did not fully succeed.
 */
class EtlRunService
{
    public function start(
        string $jobType,
        ?string $tenantId,
        string $businessDate,
        string $version,
        string $trigger = 'schedule',
    ): EtlRun {
        $key = [
            'job_type' => $jobType,
            'tenant_id' => $tenantId,
            'business_date' => $businessDate,
            'version' => $version,
        ];

        $existing = EtlRun::query()->withoutGlobalScopes()->where($key)->first();

        $retryCount = $existing && in_array($existing->status, [EtlRun::STATUS_FAILED, EtlRun::STATUS_PARTIAL], true)
            ? (int) $existing->retry_count + 1
            : (int) ($existing->retry_count ?? 0);

        return EtlRun::query()->withoutGlobalScopes()->updateOrCreate($key, [
            'status' => EtlRun::STATUS_RUNNING,
            'started_at' => CarbonImmutable::now(),
            'completed_at' => null,
            'retry_count' => $retryCount,
            'trigger' => $trigger,
            'error_summary' => null,
        ]);
    }

    public function complete(EtlRun $run, EtlDatasetResult $result): EtlRun
    {
        $succeeded = $result->insertedCount + $result->updatedCount;
        $status = match (true) {
            $result->failedCount === 0 => EtlRun::STATUS_COMPLETED,
            $succeeded > 0 => EtlRun::STATUS_PARTIAL,
            default => EtlRun::STATUS_FAILED,
        };

        $completedAt = CarbonImmutable::now();

        $run->fill(array_merge($result->toArray(), [
            'status' => $status,
            'completed_at' => $completedAt,
            'duration_ms' => $run->started_at ? $run->started_at->diffInMilliseconds($completedAt) : null,
        ]));
        $run->save();

        return $run;
    }

    public function fail(EtlRun $run, Throwable $e): EtlRun
    {
        $completedAt = CarbonImmutable::now();

        $run->fill([
            'status' => EtlRun::STATUS_FAILED,
            'completed_at' => $completedAt,
            'duration_ms' => $run->started_at ? $run->started_at->diffInMilliseconds($completedAt) : null,
            'error_summary' => [$e->getMessage()],
        ]);
        $run->save();

        return $run;
    }

    public function latest(string $jobType, ?string $tenantId, string $businessDate, string $version): ?EtlRun
    {
        return EtlRun::query()->withoutGlobalScopes()->where([
            'job_type' => $jobType,
            'tenant_id' => $tenantId,
            'business_date' => $businessDate,
            'version' => $version,
        ])->first();
    }

    /** Most recent completed/partial run for a tenant, across all datasets — used for the data-freshness banner (Section 17). */
    public function lastSuccessfulAt(string $tenantId): ?CarbonImmutable
    {
        $run = EtlRun::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [EtlRun::STATUS_COMPLETED, EtlRun::STATUS_PARTIAL])
            ->orderByDesc('completed_at')
            ->first();

        return $run?->completed_at ? CarbonImmutable::instance($run->completed_at) : null;
    }
}
