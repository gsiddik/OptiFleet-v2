<?php

namespace App\Jobs\Intelligence;

use App\Domain\Intelligence\Services\PredictionRunService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class RunPredictionJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public array $backoff;

    public int $timeout;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $target,
        public readonly string $businessDate,
    ) {
        $this->tries = 1 + (int) config('intelligence.job.max_retries', 3);
        $this->backoff = config('intelligence.job.retry_backoff_seconds', [60, 300, 900]);
        $this->timeout = (int) config('intelligence.job.job_timeout_seconds', 900);
    }

    public function handle(PredictionRunService $runner): void
    {
        $runner->runForTenant($this->tenantId, $this->target, $this->businessDate);
    }

    public function failed(Throwable $e): void
    {
        Log::error('intelligence.prediction.retries_exhausted', [
            'tenant_id' => $this->tenantId, 'target' => $this->target, 'error' => $e->getMessage(),
        ]);
    }
}
