<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\Services\OutcomeFeedbackService;
use App\Domain\Intelligence\Services\PredictionRunService;
use Illuminate\Console\Command;

/**
 * Phase 7 Section 40-41, 48 — sweeps matured predictions and records
 * ground-truth outcomes, feeding future model monitoring/retraining.
 *   intelligence:evaluate-outcomes --tenant=<uuid>
 */
class IntelligenceEvaluateOutcomesCommand extends Command
{
    protected $signature = 'intelligence:evaluate-outcomes {--tenant= : Restrict to a single tenant ID.}';

    protected $description = 'Evaluate matured Phase 7 predictions against actual outcomes.';

    public function handle(OutcomeFeedbackService $service, PredictionRunService $tenants): int
    {
        foreach ($tenants->eligibleTenants($this->option('tenant')) as $tenant) {
            foreach (array_keys(config('intelligence.model_targets', [])) as $target) {
                $count = $service->evaluateMaturedPredictions($tenant->id, $target);
                if ($count > 0) {
                    $this->line("tenant={$tenant->id} target={$target} evaluated={$count}");
                }
            }
        }

        $this->info('Outcome evaluation completed.');

        return self::SUCCESS;
    }
}
