<?php

namespace App\Jobs\Intelligence;

use App\Domain\Intelligence\Services\FeatureRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One job per tenant+dataset+date (Section 13 failure isolation),
 * mirroring App\Jobs\Analytics\RunDatasetEtlJob.
 */
class RunFeatureDatasetJob implements ShouldQueue
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
        $this->tries = 1 + (int) config('intelligence.job.max_retries', 3);
        $this->backoff = config('intelligence.job.retry_backoff_seconds', [60, 300, 900]);
        $this->timeout = (int) config('intelligence.job.job_timeout_seconds', 900);
    }

    public function handle(FeatureRunService $runner): void
    {
        $runner->runDataset($this->tenantId, $this->datasetKey, $this->businessDate, $this->trigger);
    }

    public function failed(Throwable $e): void
    {
        Log::error('intelligence.feature.dataset_retries_exhausted', [
            'dataset' => $this->datasetKey, 'tenant_id' => $this->tenantId,
            'business_date' => $this->businessDate, 'error' => $e->getMessage(),
        ]);
    }
}
