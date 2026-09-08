<?php

namespace App\Console\Commands;

use App\Domain\Analytics\Services\AnalyticsRunService;
use Illuminate\Console\Command;

/**
 * Phase 6 Section 7/12. Examples:
 *   analytics:run                          # all tenants, yesterday (each tenant's own timezone), queued
 *   analytics:run --date=2026-09-06        # all tenants, explicit business date, queued
 *   analytics:run --tenant=<uuid>          # single tenant, queued
 *   analytics:run --dataset=fleet_snapshot # single dataset, all eligible tenants
 *   analytics:run --sync                   # run inline, no queue worker required (tests / smoke checks)
 */
class AnalyticsRunCommand extends Command
{
    protected $signature = 'analytics:run
        {--date= : Business date (Y-m-d). Defaults to "yesterday" in each tenant\'s own timezone.}
        {--tenant= : Restrict to a single tenant ID.}
        {--dataset= : Restrict to a single dataset key.}
        {--sync : Run inline instead of dispatching to the queue.}';

    protected $description = 'Run the daily analytics ETL (PostgreSQL -> MongoDB projections).';

    public function handle(AnalyticsRunService $runner): int
    {
        $summary = $runner->run(
            dateOverride: $this->option('date'),
            tenantId: $this->option('tenant'),
            datasetKey: $this->option('dataset'),
            trigger: 'manual',
            sync: (bool) $this->option('sync'),
        );

        if ($this->option('sync')) {
            foreach ($summary as $row) {
                $this->line(sprintf(
                    '[%s] tenant=%s dataset=%s date=%s processed=%d failed=%d',
                    $row['status'],
                    $row['tenant_id'],
                    $row['dataset'],
                    $row['business_date'],
                    $row['processed'],
                    $row['failed'],
                ));
            }
            $this->info(count($summary).' dataset run(s) completed.');
        } else {
            $this->info('Analytics ETL dispatched to the queue.');
        }

        return self::SUCCESS;
    }
}
