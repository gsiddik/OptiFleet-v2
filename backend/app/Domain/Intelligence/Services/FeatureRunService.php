<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Analytics\Models\EtlRun;
use App\Domain\Analytics\Services\EtlRunService;
use App\Domain\Analytics\Support\BusinessDateResolver;
use App\Domain\Entitlement\Services\EntitlementService;
use App\Domain\Identity\Models\Tenant;
use App\Jobs\Intelligence\RunFeatureDatasetJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Feature-generation orchestration, mirroring
 * App\Domain\Analytics\Services\AnalyticsRunService (Section 79 — same
 * execution shape: one place actually runs an extractor, both sync CLI
 * and queued-job paths call it, run tracking reuses Phase 6's EtlRun
 * collection with job_type = the feature extractor's key).
 */
class FeatureRunService
{
    public function __construct(
        private readonly FeatureDatasetRegistry $registry,
        private readonly EtlRunService $runs,
        private readonly EntitlementService $entitlements,
        private readonly BusinessDateResolver $businessDates,
    ) {}

    /** Tenants eligible: ACTIVE + MAINTENANCE_INTELLIGENCE module entitlement. */
    public function eligibleTenants(?string $tenantId = null): Collection
    {
        $query = Tenant::query()->withoutGlobalScopes()->where('status', 'ACTIVE');
        if ($tenantId) {
            $query->where('id', $tenantId);
        }

        return $query->get()->filter(fn (Tenant $tenant) => $this->entitlements->tenantHasModule($tenant->id, 'MAINTENANCE_INTELLIGENCE'))->values();
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

    public function run(?string $dateOverride, ?string $tenantId, ?string $datasetKey, string $trigger = 'manual', bool $sync = false): array
    {
        $datasetKeys = $this->datasetKeys($datasetKey);
        $tenants = $this->eligibleTenants($tenantId);
        $summary = [];

        foreach ($tenants as $tenant) {
            $businessDate = $this->resolveBusinessDate($tenant, $dateOverride);

            foreach ($datasetKeys as $key) {
                if (! $sync) {
                    RunFeatureDatasetJob::dispatch($tenant->id, $key, $businessDate, $trigger);

                    continue;
                }

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

    public function runDataset(string $tenantId, string $datasetKey, string $businessDate, string $trigger): EtlRun
    {
        $extractor = $this->registry->get($datasetKey);
        $run = $this->runs->start($extractor->key(), $tenantId, $businessDate, $extractor->version(), $trigger);

        try {
            $result = $extractor->run($tenantId, CarbonImmutable::createFromFormat('Y-m-d', $businessDate, 'UTC'));
            $run = $this->runs->complete($run, $result);

            Log::info('intelligence.feature.dataset_completed', [
                'dataset' => $datasetKey, 'tenant_id' => $tenantId, 'business_date' => $businessDate,
                'status' => $run->status, 'source_count' => $run->source_count,
                'processed_count' => $run->processed_count, 'duration_ms' => $run->duration_ms,
            ]);

            return $run;
        } catch (Throwable $e) {
            $this->runs->fail($run, $e);

            Log::warning('intelligence.feature.dataset_failed', [
                'dataset' => $datasetKey, 'tenant_id' => $tenantId, 'business_date' => $businessDate, 'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
