<?php

namespace App\Jobs\Intelligence;

use App\Domain\Intelligence\Services\OutcomeFeedbackService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Queued form of `intelligence:evaluate-outcomes` for one tenant + target. */
class EvaluateOutcomesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries;

    public array $backoff;

    public int $timeout;

    public function __construct(
        public readonly string $tenantId,
        public readonly string $target,
    ) {
        $this->tries = 1 + (int) config('intelligence.job.max_retries', 3);
        $this->backoff = config('intelligence.job.retry_backoff_seconds', [60, 300, 900]);
        $this->timeout = (int) config('intelligence.job.job_timeout_seconds', 900);
    }

    public function handle(OutcomeFeedbackService $service): void
    {
        $service->evaluateMaturedPredictions($this->tenantId, $this->target);
    }

    public function failed(Throwable $e): void
    {
        Log::error('intelligence.outcome_evaluation.retries_exhausted', [
            'tenant_id' => $this->tenantId, 'target' => $this->target, 'error' => $e->getMessage(),
        ]);
    }
}
