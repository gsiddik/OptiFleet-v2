<?php

namespace App\Console\Commands;

use App\Domain\Analytics\Services\AnalyticsReconciliationService;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Phase 6 Section 61 CLI entry point — used by the release-gate check and
 * available for ad-hoc verification.
 */
class AnalyticsReconcileCommand extends Command
{
    protected $signature = 'analytics:reconcile
        {--date= : Business date (Y-m-d). Defaults to yesterday.}
        {--tenant= : Restrict to a single tenant ID. Defaults to all tenants.}';

    protected $description = 'Reconcile PostgreSQL source counts against MongoDB analytical projections.';

    public function handle(AnalyticsReconciliationService $reconciliation): int
    {
        $date = $this->option('date') ?: now()->subDay()->format('Y-m-d');
        $tenants = $this->option('tenant')
            ? Tenant::query()->withoutGlobalScopes()->where('id', $this->option('tenant'))->get()
            : Tenant::query()->withoutGlobalScopes()->where('status', 'ACTIVE')->get();

        $mismatches = 0;
        foreach ($tenants as $tenant) {
            $result = $reconciliation->reconcile($tenant->id, $date);
            foreach ($result['checks'] as $check) {
                $status = $check['matches'] ? 'OK' : 'MISMATCH';
                $this->line(sprintf('[%s] tenant=%s %s pg=%d mongo=%d diff=%d', $status, $tenant->id, $check['check'], $check['postgres_count'], $check['mongo_count'], $check['difference']));
                if (! $check['matches']) {
                    $mismatches++;
                }
            }
        }

        $this->info($mismatches === 0 ? 'Reconciliation clean.' : "{$mismatches} mismatch(es) found.");

        return $mismatches === 0 ? self::SUCCESS : self::FAILURE;
    }
}
