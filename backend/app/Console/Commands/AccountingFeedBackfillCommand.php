<?php

namespace App\Console\Commands;

use App\Domain\Integration\AccountingFeed\AccountingFeedProjector;
use Illuminate\Console\Command;

class AccountingFeedBackfillCommand extends Command
{
    protected $signature = 'accounting-feed:backfill {--tenant= : Only this tenant id}';

    protected $description = 'Add feed events for integration outbox rows that have none (rows older than the feed, or a failed projection); idempotent';

    public function handle(AccountingFeedProjector $projector): int
    {
        $r = $projector->backfill($this->option('tenant'));
        $this->info("Projected {$r['projected']}, skipped {$r['skipped']}.");

        return self::SUCCESS;
    }
}
