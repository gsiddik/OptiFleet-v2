<?php

namespace App\Jobs\Analytics;

use App\Domain\Analytics\Services\AnalyticsRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Top of the Phase 6 ETL orchestration tree (Section 13): the one job the
 * scheduler / `analytics:run` (no --tenant) enqueues. Fans out to one
 * RunTenantAnalyticsJob per eligible tenant (ACTIVE + ANALYTICS
 * entitlement) so a single tenant's infrastructure-level failure can
 * never block another tenant's daily run.
 */
class RunDailyAnalyticsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(
        public readonly ?string $dateOverride = null,
        public readonly ?string $datasetKey = null,
        public readonly string $trigger = 'schedule',
    ) {}

    public function handle(AnalyticsRunService $runner): void
    {
        $datasetKeys = $runner->datasetKeys($this->datasetKey);

        foreach ($runner->eligibleTenants() as $tenant) {
            $businessDate = $runner->resolveBusinessDate($tenant, $this->dateOverride);
            RunTenantAnalyticsJob::dispatch($tenant->id, $businessDate, $datasetKeys, $this->trigger);
        }
    }
}
