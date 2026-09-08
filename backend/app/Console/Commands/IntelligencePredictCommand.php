<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\Services\PredictionRunService;
use Illuminate\Console\Command;

/**
 * Phase 7 Section 46-47 batch inference.
 *   intelligence:predict --sync
 *   intelligence:predict --tenant=<uuid> --target=vehicle_failure_risk --date=2026-09-06 --sync
 */
class IntelligencePredictCommand extends Command
{
    protected $signature = 'intelligence:predict
        {--date= : Business date (Y-m-d). Defaults to "yesterday" in each tenant\'s own timezone.}
        {--tenant= : Restrict to a single tenant ID.}
        {--target= : Restrict to a single model target.}
        {--sync : Run inline instead of dispatching to the queue.}';

    protected $description = 'Run Phase 7 batch inference (feature store -> predictions).';

    public function handle(PredictionRunService $runner): int
    {
        $sync = (bool) $this->option('sync');
        $summary = $runner->run($this->option('date'), $this->option('tenant'), $this->option('target'), $sync);

        if ($sync) {
            foreach ($summary as $row) {
                $this->line("[{$row['status']}] tenant={$row['tenant_id']} target={$row['target']} processed={$row['processed']}");
            }
            $this->info(count($summary).' prediction run(s) completed.');
        } else {
            $this->info('Prediction runs dispatched to the queue.');
        }

        return self::SUCCESS;
    }
}
