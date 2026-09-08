<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\Services\FeatureRunService;
use Illuminate\Console\Command;

/**
 * Phase 7 Section 4, 12 — generates the intelligence feature store.
 * Mirrors analytics:run (App\Console\Commands\AnalyticsRunCommand).
 *   intelligence:generate-features
 *   intelligence:generate-features --date=2026-09-06
 *   intelligence:generate-features --tenant=<uuid> --dataset=vehicle_features --sync
 */
class IntelligenceFeaturesCommand extends Command
{
    protected $signature = 'intelligence:generate-features
        {--date= : Feature date (Y-m-d). Defaults to "yesterday" in each tenant\'s own timezone.}
        {--tenant= : Restrict to a single tenant ID.}
        {--dataset= : Restrict to a single feature dataset key.}
        {--sync : Run inline instead of dispatching to the queue.}';

    protected $description = 'Generate the Phase 7 intelligence feature store (PostgreSQL -> MongoDB feature projections).';

    public function handle(FeatureRunService $runner): int
    {
        $sync = (bool) $this->option('sync');
        $summary = $runner->run(
            dateOverride: $this->option('date'),
            tenantId: $this->option('tenant'),
            datasetKey: $this->option('dataset'),
            trigger: 'manual',
            sync: $sync,
        );

        if ($sync) {
            foreach ($summary as $row) {
                $this->line(sprintf(
                    '[%s] tenant=%s dataset=%s date=%s processed=%d failed=%d',
                    $row['status'], $row['tenant_id'], $row['dataset'], $row['business_date'], $row['processed'], $row['failed'],
                ));
            }
            $this->info(count($summary).' feature dataset run(s) completed.');
        } else {
            $this->info('Feature generation dispatched to the queue.');
        }

        return self::SUCCESS;
    }
}
