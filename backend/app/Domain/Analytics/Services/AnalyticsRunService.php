<?php

namespace App\Domain\Analytics\Services;

use App\Domain\Analytics\Models\EtlRun;
use App\Domain\Analytics\Support\BusinessDateResolver;
use App\Domain\Entitlement\Services\EntitlementService;
use App\Domain\Identity\Models\Tenant;
use App\Jobs\Analytics\RunDailyAnalyticsJob;
use App\Jobs\Analytics\RunDatasetEtlJob;
use App\Jobs\Analytics\RunTenantAnalyticsJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Thin orchestration used by both `analytics:run` / `analytics:backfill`
 * and the daily scheduled job (Section 8/12/13). runDataset() is the one
 * place that actually starts/executes/completes a dataset extraction —
 * both the queued job path (RunDatasetEtlJob) and the synchronous CLI
 * path (--sync, used by tests and manual smoke checks) call it, so
 * there is exactly one execution code path regardless of how it was
 * triggered.
 */
class AnalyticsRunService
{
    public function __construct(
        private readonly DatasetRegistry $registry,
        private readonly EtlRunService $runs,
        private readonly EntitlementService $entitlements,
        private readonly BusinessDateResolver $businessDates,
    ) {}

    /** Tenants eligible for analytics processing: ACTIVE + ANALYTICS module entitlement. */
    public function eligibleTenants(?string $tenantId = null): Collection
    {
        $query = Tenant::query()->withoutGlobalScopes()->where('status', 'ACTIVE');
        if ($tenantId) {
            $query->where('id', $tenantId);
        }

        return $query->get()->filter(fn (Tenant $tenant) => $this->entitlements->tenantHasModule($tenant->id, 'ANALYTICS'))->values();
    }

    /** @return string[] */
    public function datasetKeys(?string $datasetKey = null): array
    {
        if ($datasetKey) {
            return $this->registry->has($datasetKey) ? [$datasetKey] : [];
        }

        return $this->registry->keys();
    }

    public function resolveBusinessDate(Tenant $tenant, ?string $dateOverride): string
    {
        return $dateOverride ?: $this->businessDates->yesterdayFor($tenant)->format('Y-m-d');
    }

    /**
     * Entry point for `analytics:run`. Returns a per-tenant/per-dataset
     * summary for CLI output when $sync is true; when $sync is false the
     * work is fully async (queued) and this returns dispatch confirmation
     * only.
     */
    public function run(
        ?string $dateOverride,
        ?string $tenantId,
        ?string $datasetKey,
        string $trigger = 'manual',
        bool $sync = false,
    ): array {
        $datasetKeys = $this->datasetKeys($datasetKey);

        // Async + all tenants: fan out via the parent orchestration job
        // (Section 13) rather than resolving/dispatching per tenant here.
        if (! $sync && ! $tenantId) {
            RunDailyAnalyticsJob::dispatch($dateOverride, $datasetKey, $trigger);

            return [];
        }

        $tenants = $this->eligibleTenants($tenantId);
        $summary = [];

        foreach ($tenants as $tenant) {
            $businessDate = $this->resolveBusinessDate($tenant, $dateOverride);

            if (! $sync) {
                RunTenantAnalyticsJob::dispatch($tenant->id, $businessDate, $datasetKeys, $trigger);

                continue;
            }

            foreach ($datasetKeys as $key) {
                $run = $this->runDataset($tenant->id, $key, $businessDate, $trigger);
                $summary[] = [
                    'tenant_id' => $tenant->id,
                    'dataset' => $key,
                    'business_date' => $businessDate,
                    'status' => $run->status,
                    'processed' => $run->processed_count,
                    'failed' => $run->failed_count,
                ];
            }
        }

        return $summary;
    }

    /**
     * The one place a dataset is actually extracted + written (Sections
     * 8-9). Always starts a tracked run, always leaves it in a terminal
     * state (COMPLETED/PARTIAL/FAILED) even on exception, and rethrows so
     * the caller (queued job) can apply Laravel's own retry/backoff.
     */
    public function runDataset(string $tenantId, string $datasetKey, string $businessDate, string $trigger): EtlRun
    {
        $extractor = $this->registry->get($datasetKey);
        $run = $this->runs->start($extractor->key(), $tenantId, $businessDate, $extractor->version(), $trigger);

        try {
            $result = $extractor->run($tenantId, CarbonImmutable::createFromFormat('Y-m-d', $businessDate, 'UTC'));
            $run = $this->runs->complete($run, $result);

            Log::info('analytics.etl.dataset_completed', [
                'dataset' => $datasetKey,
                'tenant_id' => $tenantId,
                'business_date' => $businessDate,
                'status' => $run->status,
                'source_count' => $run->source_count,
                'processed_count' => $run->processed_count,
                'failed_count' => $run->failed_count,
                'duration_ms' => $run->duration_ms,
            ]);

            return $run;
        } catch (Throwable $e) {
            $this->runs->fail($run, $e);

            Log::warning('analytics.etl.dataset_failed', [
                'dataset' => $datasetKey,
                'tenant_id' => $tenantId,
                'business_date' => $businessDate,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function runDatasetsForTenant(string $tenantId, string $businessDate, array $datasetKeys, string $trigger): void
    {
        foreach ($datasetKeys as $key) {
            RunDatasetEtlJob::dispatch($tenantId, $key, $businessDate, $trigger);
        }
    }
}
