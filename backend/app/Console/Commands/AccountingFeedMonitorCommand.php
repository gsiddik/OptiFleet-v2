<?php

namespace App\Console\Commands;

use App\Domain\Integration\AccountingFeed\AccountingFeedMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class AccountingFeedMonitorCommand extends Command
{
    protected $signature = 'accounting-feed:monitor {--tenant= : Only this tenant id} {--grace= : Minutes a fact may be without an event before it counts} {--json : Print the report as JSON}';

    protected $description = 'Report financial facts that never reached the accounting feed (missed projections); exits non-zero and logs critical when there are any';

    public function handle(AccountingFeedMonitor $monitor): int
    {
        $report = $monitor->report($this->option('tenant') ?: null, $this->option('grace') !== null ? (int) $this->option('grace') : null);

        foreach ($report['tenants'] as $tenantId => $row) {
            Log::critical('accounting_feed.missed_projections', ['tenant_id' => $tenantId] + $row);
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } elseif ($report['tenants'] === []) {
            $this->info('No missed projections.');
        } else {
            $this->table(['Tenant', 'Missed (repairable)', 'Source rows gone', 'Oldest'], collect($report['tenants'])->map(fn ($r, $id) => [$id, $r['missed'], $r['unbuildable'], $r['oldest_missed_at']])->values()->all());
            $this->warn('Repair with accounting-feed:backfill --dry-run first; waive a fact on purpose with accounting-feed:waive.');
        }

        return $report['tenants'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
