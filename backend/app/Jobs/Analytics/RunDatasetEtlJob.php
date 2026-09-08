<?php

namespace App\Jobs\Analytics;

use App\Domain\Analytics\Services\AnalyticsRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Leaf job of the Phase 6 ETL orchestration tree (Section 13):
 * RunDailyAnalyticsJob -> RunTenantAnalyticsJob -> RunDatasetEtlJob. One
 * job per tenant+dataset+date, so a failure processing tenant A never
 * blocks tenant B, and a failure in one dataset never blocks another.
 *
 * Retry (Section 14) is Laravel's own queue retry/backoff — configured
 * from config/analytics.php, not a custom loop. AnalyticsRunService
 * already leaves the tracked EtlRun in a terminal FAILED state before
 * this rethrows, so the failure is never silently lost even once retries
 * are exhausted.
 */
class RunDatasetEtlJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public array $backoff;

    public int $timeout;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $datasetKey,
        public readonly string $businessDate,
        public readonly string $trigger = 'schedule',
    ) {
        $this->tries = 1 + (int) config('analytics.max_retries', 3);
        $this->backoff = config('analytics.retry_backoff_seconds', [60, 300, 900]);
        $this->timeout = (int) config('analytics.job_timeout_seconds', 600);
    }

    public function handle(AnalyticsRunService $runner): void
    {
        $runner->runDataset($this->tenantId, $this->datasetKey, $this->businessDate, $this->trigger);
    }

    public function failed(Throwable $e): void
    {
        Log::error('analytics.etl.dataset_retries_exhausted', [
            'dataset' => $this->datasetKey,
            'tenant_id' => $this->tenantId,
            'business_date' => $this->businessDate,
            'error' => $e->getMessage(),
        ]);
    }
}
