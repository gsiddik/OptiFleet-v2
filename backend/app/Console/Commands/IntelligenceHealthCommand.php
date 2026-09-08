<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\Services\HealthScoreRunService;
use App\Domain\Intelligence\Services\PredictionRunService;
use Illuminate\Console\Command;

/**
 * Phase 7 Section 21-23 — computes governed vehicle/component health
 * scores. Run after intelligence:predict for the same business date so
 * the predictive_risk subscore can read that day's just-computed
 * vehicle_failure_risk prediction (Section 47 pipeline ordering).
 *   intelligence:health --tenant=<uuid> --date=2026-09-06 --sync
 */
class IntelligenceHealthCommand extends Command
{
    protected $signature = 'intelligence:health
        {--date= : Business date (Y-m-d). Defaults to "yesterday" in each tenant\'s own timezone.}
        {--tenant= : Restrict to a single tenant ID.}
        {--sync : Run inline instead of dispatching to the queue.}';

    protected $description = 'Compute Phase 7 governed vehicle/component health scores.';

    public function handle(HealthScoreRunService $service, PredictionRunService $predictionRunner): int
    {
        $sync = (bool) $this->option('sync');
        $tenants = $predictionRunner->eligibleTenants($this->option('tenant'));

        foreach ($tenants as $tenant) {
            $businessDate = $this->option('date') ?: now()->subDay()->format('Y-m-d');

            if (! $sync) {
                \App\Jobs\Intelligence\RunHealthScoreJob::dispatch($tenant->id, $businessDate);

                continue;
            }

            $counts = $service->runForTenant($tenant->id, $businessDate);
            $this->line("tenant={$tenant->id} date={$businessDate} vehicles={$counts['vehicle']} components={$counts['component']}");
        }

        $this->info($sync ? 'Health scoring completed.' : 'Health scoring dispatched to the queue.');

        return self::SUCCESS;
    }
}
