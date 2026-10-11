<?php

namespace App\Domain\Integration\AccountingFeed;

use App\Domain\Integration\Models\IntegrationOutboxEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Write side of the accounting feed: gives each integration outbox row its canonical event and a per-tenant
 * sequence. It is the only writer of accounting_feed_events.
 *
 * Gap-free and commit-ordered: the sequence comes from a per-tenant counter row whose lock lives until the
 * surrounding transaction commits. Two writers of the same tenant therefore commit in sequence order, a rollback
 * releases the number, and a reader that has seen sequence N can never miss an N-1 that is still in flight.
 *
 * Normal path: the model listener registered by AccountingFeedServiceProvider calls project() when the outbox row is
 * created, inside the business transaction (the outbox row, the feed event and the invoice commit or roll back
 * together). backfill() heals rows that predate the feed or whose projection failed; it is idempotent on the
 * outbox row id and uses the current source state (snapshot_basis = backfill_current_state).
 */
class AccountingFeedProjector
{
    public function __construct(private readonly AccountingFeedSnapshotBuilder $builder) {}

    public function project(IntegrationOutboxEvent $row, string $basis = 'event_time'): ?AccountingFeedEvent
    {
        if (AccountingFeedEvent::query()->withoutGlobalScopes()->where('event_id', $row->id)->exists()) {
            return null;
        }

        $snapshot = $this->builder->build($row, $basis);
        if ($snapshot === null) {
            return null;
        }

        // Nested inside the caller's transaction this is a savepoint: a failure here rolls back the counter bump too.
        return DB::transaction(fn () => AccountingFeedEvent::query()->withoutGlobalScopes()->create([
            'tenant_id' => $row->tenant_id,
            'sequence' => $this->nextSequence((string) $row->tenant_id),
            'event_id' => $row->id,
        ] + $snapshot));
    }

    /**
     * Listener entry point: the feed must never be the reason an invoice cannot be recorded.
     */
    public function projectSafely(IntegrationOutboxEvent $row): void
    {
        try {
            $this->project($row);
        } catch (Throwable $e) {
            Log::error('Accounting feed projection failed; run accounting-feed:backfill.', ['outbox_event_id' => $row->id, 'error' => $e->getMessage()]);
        }
    }

    /**
     * @return array{projected: int, skipped: int}
     */
    public function backfill(?string $tenantId = null): array
    {
        $result = ['projected' => 0, 'skipped' => 0];

        $rows = IntegrationOutboxEvent::query()->withoutGlobalScopes()
            ->whereIn('event_type', AccountingFeedSnapshotBuilder::EVENT_TYPES)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('accounting_feed_events')->whereColumn('accounting_feed_events.event_id', 'integration_outbox_events.id'))
            ->orderBy('created_at')->orderBy('id')
            ->cursor();

        foreach ($rows as $row) {
            try {
                $event = $this->project($row, 'backfill_current_state');
            } catch (UniqueConstraintViolationException) {
                $event = null; // projected concurrently by another process
            }
            $event ? $result['projected']++ : $result['skipped']++;
        }

        return $result;
    }

    private function nextSequence(string $tenantId): int
    {
        $row = DB::connection()->selectFromWriteConnection(
            'INSERT INTO accounting_feed_counters (tenant_id, last_sequence) VALUES (?, 1)
             ON CONFLICT (tenant_id) DO UPDATE SET last_sequence = accounting_feed_counters.last_sequence + 1
             RETURNING last_sequence',
            [$tenantId],
        );

        return (int) $row[0]->last_sequence;
    }
}
