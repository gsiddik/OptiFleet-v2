<?php

namespace App\Console\Commands;

use App\Domain\Intelligence\Services\DiagnosticsRunService;
use App\Domain\Intelligence\Services\PredictionRunService;
use App\Jobs\Intelligence\RunDiagnosticsJob;
use Illuminate\Console\Command;

/**
 * Phase 7 Section 24-27 — RUL, repeat failure, anomaly detection.
 *   intelligence:diagnostics --tenant=<uuid> --date=2026-09-06 --sync
 */
class IntelligenceDiagnosticsCommand extends Command
{
    protected $signature = 'intelligence:diagnostics
        {--date= : Business date (Y-m-d). Defaults to "yesterday" in each tenant\'s own timezone.}
        {--tenant= : Restrict to a single tenant ID.}
        {--sync : Run inline instead of dispatching to the queue.}';

    protected $description = 'Run Phase 7 RUL / repeat-failure / anomaly diagnostics.';

    public function handle(DiagnosticsRunService $service, PredictionRunService $tenants): int
    {
        $sync = (bool) $this->option('sync');
        foreach ($tenants->eligibleTenants($this->option('tenant')) as $tenant) {
            $businessDate = $this->option('date') ?: now()->subDay()->format('Y-m-d');

            if (! $sync) {
                RunDiagnosticsJob::dispatch($tenant->id, $businessDate);

                continue;
            }

            $result = $service->runForTenant($tenant->id, $businessDate);
            $this->line("tenant={$tenant->id} date={$businessDate} written={$result['written']}");
        }

        $this->info($sync ? 'Diagnostics completed.' : 'Diagnostics dispatched to the queue.');

        return self::SUCCESS;
    }
}
