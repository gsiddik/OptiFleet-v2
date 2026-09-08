<?php

namespace App\Jobs\Intelligence;

use App\Domain\Intelligence\Services\TrainingPipelineService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 7 Section 42: training never runs inside an HTTP request. One
 * job per tenant+target, so a training failure never blocks another
 * tenant's or target's training (Section 13).
 */
class TrainModelJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public array $backoff;

    public int $timeout;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $target,
        public readonly string $scope,
        public readonly string $createdBy = 'schedule',
    ) {
        $this->tries = 1 + (int) config('intelligence.job.max_retries', 3);
        $this->backoff = config('intelligence.job.retry_backoff_seconds', [60, 300, 900]);
        $this->timeout = (int) config('intelligence.job.job_timeout_seconds', 900);
    }

    public function handle(TrainingPipelineService $pipeline): void
    {
        $model = $pipeline->train($this->tenantId, $this->target, $this->scope, $this->createdBy);

        Log::info('intelligence.training.completed', [
            'tenant_id' => $this->tenantId, 'target' => $this->target, 'status' => $model->status,
            'model_id' => (string) $model->id, 'version' => $model->version,
        ]);
    }

    public function failed(Throwable $e): void
    {
        Log::error('intelligence.training.retries_exhausted', [
            'tenant_id' => $this->tenantId, 'target' => $this->target, 'error' => $e->getMessage(),
        ]);
    }
}
