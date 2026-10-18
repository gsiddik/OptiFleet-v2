<?php

namespace App\Console\Commands;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Integration\AccountingFeed\AccountingFeedEvent;
use App\Domain\Integration\AccountingFeed\AccountingFeedProjector;
use App\Domain\Integration\Models\IntegrationOutboxEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountingFeedWaiveCommand extends Command
{
    protected $signature = 'accounting-feed:waive {outbox_event : Integration outbox event id} {--by= : Who decides} {--reason= : Why it will never be in the feed (at least 10 characters)}';

    protected $description = 'Record, on purpose, that one outbox fact will not be published to the accounting feed (it stops alerting and is never repaired)';

    public function handle(AccountingFeedProjector $projector, AuditService $audit): int
    {
        $id = (string) $this->argument('outbox_event');
        $by = trim((string) $this->option('by'));
        $reason = trim((string) $this->option('reason'));
        $row = Str::isUuid($id) ? IntegrationOutboxEvent::query()->withoutGlobalScopes()->find($id) : null;

        if (! $row) {
            $this->error("Outbox event '{$id}' not found.");

            return self::FAILURE;
        }
        if ($row && AccountingFeedEvent::query()->withoutGlobalScopes()->where('event_id', $row->id)->exists()) {
            $this->error('This fact already has a feed event; there is nothing to waive.');

            return self::FAILURE;
        }
        if ($by === '' || mb_strlen($reason) < 10) {
            $this->error('Waiving needs --by and a --reason of at least 10 characters.');

            return self::FAILURE;
        }

        $projector->noteIssue($row, 'waived', mb_substr($reason, 0, 500));
        DB::table('accounting_feed_projection_issues')->where('outbox_event_id', $row->id)
            ->update(['resolved_at' => now(), 'resolution' => 'waived', 'resolved_by' => $by]);
        $audit->log('accounting_feed', (string) $row->id, 'accounting_feed.fact_waived', null, ['by' => $by, 'reason' => $reason, 'event_type' => $row->event_type], (string) $row->tenant_id);
        $this->info("Outbox event {$row->id} ({$row->event_type}) waived.");

        return self::SUCCESS;
    }
}
