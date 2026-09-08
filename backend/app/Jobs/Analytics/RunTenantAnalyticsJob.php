<?php

namespace App\Jobs\Analytics;

use App\Domain\Analytics\Services\AnalyticsRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Middle tier of the Phase 6 ETL orchestration tree (Section 13): fans one
 * tenant's daily run out into one RunDatasetEtlJob per dataset, so one
 * dataset failing never blocks the others for the same tenant.
 */
class RunTenantAnalyticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $businessDate,
        public readonly array $datasetKeys,
        public readonly string $trigger = 'schedule',
    ) {}

    public function handle(AnalyticsRunService $runner): void
    {
        $runner->runDatasetsForTenant($this->tenantId, $this->businessDate, $this->datasetKeys, $this->trigger);
    }
}
