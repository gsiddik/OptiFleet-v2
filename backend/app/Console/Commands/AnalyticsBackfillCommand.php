<?php

namespace App\Console\Commands;

use App\Domain\Analytics\Services\AnalyticsRunService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Phase 6 Section 12 (backfill) + Section 11 (late-arriving corrections):
 * reprocessing a date is just re-running the same idempotent ETL for it —
 * the unique key on each snapshot collection guarantees an upsert in
 * place rather than a duplicate, so backfill is also how a corrected Work
 * Order (etc.) gets its analytical projection refreshed after the fact.
 *
 * Example: analytics:backfill --from=2026-09-01 --to=2026-09-06 --tenant=<uuid>
 */
class AnalyticsBackfillCommand extends Command
{
    protected $signature = 'analytics:backfill
        {--from= : First business date (Y-m-d), required.}
        {--to= : Last business date (Y-m-d), inclusive. Defaults to --from.}
        {--tenant= : Restrict to a single tenant ID.}
        {--dataset= : Restrict to a single dataset key.}
        {--sync : Run inline instead of dispatching to the queue.}';

    protected $description = 'Reprocess analytics for a date or date range (backfill / late-data correction).';

    public function handle(AnalyticsRunService $runner): int
    {
        $from = $this->option('from');
        if (! $from) {
            $this->error('--from is required (Y-m-d).');

            return self::FAILURE;
        }

        $to = $this->option('to') ?: $from;
        $start = CarbonImmutable::createFromFormat('Y-m-d', $from);
        $end = CarbonImmutable::createFromFormat('Y-m-d', $to);

        if ($end->lt($start)) {
            $this->error('--to must not be before --from.');

            return self::FAILURE;
        }

        $totalRuns = 0;
        for ($date = $start; $date->lte($end); $date = $date->addDay()) {
            $summary = $runner->run(
                dateOverride: $date->format('Y-m-d'),
                tenantId: $this->option('tenant'),
                datasetKey: $this->option('dataset'),
                trigger: 'backfill',
                sync: (bool) $this->option('sync'),
            );
            $totalRuns += count($summary);
            $this->line('Backfilled '.$date->format('Y-m-d').($this->option('sync') ? ' ('.count($summary).' dataset run(s))' : ' (dispatched)'));
        }

        $this->info('Backfill complete: '.$start->format('Y-m-d').' to '.$end->format('Y-m-d').($this->option('sync') ? " ({$totalRuns} total dataset run(s))" : ''));

        return self::SUCCESS;
    }
}
