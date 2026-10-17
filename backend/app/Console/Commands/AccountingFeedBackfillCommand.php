<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Integration\AccountingFeed\AccountingFeedProjector;
use Illuminate\Console\Command;

class AccountingFeedBackfillCommand extends Command
{
    protected $signature = 'accounting-feed:backfill
        {--tenant= : Only this tenant id}
        {--limit=200 : Facts repaired per tenant in this run (at most 1000)}
        {--dry-run : Count only, change nothing}
        {--retry-unbuildable : Also try facts whose source rows were missing before}
        {--by= : Who runs the repair (required unless --dry-run)}
        {--reason= : Why (at least 10 characters, required unless --dry-run)}';

    protected $description = 'Repair missed projections: append the missing feed events, quarantined for review (bounded, insert-only, audited, idempotent)';

    public function handle(AccountingFeedProjector $projector, AuditService $audit): int
    {
        $dry = (bool) $this->option('dry-run');
        $by = trim((string) $this->option('by'));
        $reason = trim((string) $this->option('reason'));
        if (! $dry && ($by === '' || mb_strlen($reason) < 10)) {
            $this->error('A repair needs --by and a --reason of at least 10 characters (use --dry-run to only count).');

            return self::FAILURE;
        }

        $limit = max(1, min((int) $this->option('limit'), AccountingFeedProjector::MAX_REPAIR_BATCH));
        $tenants = $this->option('tenant') ? [(string) $this->option('tenant')] : $projector->repairable(null, (bool) $this->option('retry-unbuildable'))->distinct()->pluck('tenant_id')->all();

        if ($tenants === []) {
            $this->info('Nothing is missing.');

            return self::SUCCESS;
        }

        foreach ($tenants as $tenantId) {
            $r = $projector->backfill($tenantId, $limit, ['by' => $by ?: null, 'reason' => $reason ?: null], $dry, (bool) $this->option('retry-unbuildable'));
            $this->line(sprintf('%s: projected %d, already there %d, source rows gone %d, repairable now %d, open in total %d%s', $tenantId, $r['projected'], $r['skipped'], $r['unbuildable'], $r['repairable'], $r['remaining'], $dry ? ' (dry run)' : ''));

            if (! $dry) {
                $audit->log('accounting_feed', $tenantId, 'accounting_feed.repair_run', null, ['by' => $by, 'reason' => $reason, 'limit' => $limit] + $r, $tenantId);
            }
        }

        $this->comment('Repaired facts are appended with new sequences and marked for review; nothing published was changed.');

        return self::SUCCESS;
    }
}
