<?php

namespace App\Domain\Integration\AccountingFeed;

use App\Domain\Integration\Models\IntegrationOutboxEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Write side of the accounting feed: gives each integration outbox row its canonical event and a per-tenant
 * sequence. It is the only writer of accounting_feed_events.
 *
 * Gap-free and commit-ordered (audit A22): `append()` takes the sequence from a per-tenant counter row whose lock lives until the
 * surrounding transaction commits. Two writers of the same tenant therefore commit in sequence order, a rollback or a crash
 * releases the number, and a reader that has seen sequence N can never miss an N-1 that is still in flight (proved against a real
 * database with parallel processes: tests/Feature/AccountingFeedConcurrencyTest.php). Writers of different tenants never wait for
 * each other.
 *
 * Normal path: the model listener registered by AccountingFeedServiceProvider calls projectSafely() when the outbox row is
 * created, inside the business transaction (the outbox row, the feed event and the invoice commit or roll back together). The
 * outbox row is the durable fact; if the projection fails, the business change still commits, the failure is recorded in
 * accounting_feed_projection_issues and reported by `accounting-feed:monitor`, and `accounting-feed:backfill` repairs it.
 *
 * Repair (audit A17) only ever INSERTS: published events are never rewritten and a repaired fact is appended with a new sequence. It is
 * built from the CURRENT state of its source, not from the state at the time, so it is marked `review` (quarantined for the consumer:
 * to be reviewed, never posted automatically) and `payload.snapshot_basis = backfill_current_state`.
 */
class AccountingFeedProjector
{
    public const BASIS_EVENT_TIME = 'event_time';

    public const BASIS_BACKFILL = 'backfill_current_state';

    /** Rows per repair run, whatever the caller asks for. */
    public const MAX_REPAIR_BATCH = 1000;

    public function __construct(private readonly AccountingFeedSnapshotBuilder $builder) {}

    /**
     * @param  array<string, mixed>|null  $review  marker for a quarantined event (see class comment); null for a live event
     */
    public function project(IntegrationOutboxEvent $row, string $basis = self::BASIS_EVENT_TIME, ?array $review = null): ?AccountingFeedEvent
    {
        if (AccountingFeedEvent::query()->withoutGlobalScopes()->where('event_id', $row->id)->exists()) {
            return null;
        }

        $snapshot = $this->builder->build($row, $basis);
        if ($snapshot === null) {
            return null;
        }

        return $this->append((string) $row->tenant_id, (string) $row->id, $snapshot, $review);
    }

    /**
     * The one place where a sequence is allocated and an event stored. Nested inside the caller's transaction this is a savepoint: a
     * failure here rolls back the counter bump too, and the counter row stays locked until the OUTER transaction ends.
     *
     * @param  array<string, mixed>  $snapshot  event_type, occurred_at, correlation_id, source, dimensions, payload
     * @param  array<string, mixed>|null  $review
     */
    public function append(string $tenantId, string $eventId, array $snapshot, ?array $review = null): AccountingFeedEvent
    {
        return DB::transaction(fn () => AccountingFeedEvent::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'sequence' => $this->nextSequence($tenantId),
            'event_id' => $eventId,
            'review' => $review,
        ] + $snapshot));
    }

    /**
     * Listener entry point: the feed must never be the reason an invoice cannot be recorded. The whole projection runs in a savepoint
     * (so a failed statement cannot poison the business transaction) and a failure leaves a visible, repairable issue.
     */
    public function projectSafely(IntegrationOutboxEvent $row): void
    {
        try {
            DB::transaction(function () use ($row): void {
                if ($this->project($row) === null && in_array($row->event_type, AccountingFeedSnapshotBuilder::EVENT_TYPES, true)
                    && ! AccountingFeedEvent::query()->withoutGlobalScopes()->where('event_id', $row->id)->exists()) {
                    $this->noteIssue($row, 'source_missing', 'The source rows of this fact could not be read.');
                }
            });
        } catch (Throwable $e) {
            Log::error('Accounting feed projection failed; it is repaired with accounting-feed:backfill.', ['outbox_event_id' => $row->id, 'tenant_id' => $row->tenant_id, 'error' => $e->getMessage()]);
            try {
                $this->noteIssue($row, 'projection_exception', mb_substr($e->getMessage(), 0, 500));
            } catch (Throwable $inner) {
                Log::error('Accounting feed projection issue could not be recorded.', ['outbox_event_id' => $row->id, 'error' => $inner->getMessage()]);
            }
        }
    }

    /**
     * Repair: adds the feed events that are missing, oldest fact first, at most `$limit` (never more than MAX_REPAIR_BATCH). Idempotent
     * on the outbox row id. Every event it adds is quarantined (`review`). Nothing that was published is touched.
     *
     * Facts waived on purpose are never repaired; facts whose source rows are gone are left alone (and keep alerting) unless
     * `$retryUnbuildable` is set. `remaining` counts every fact still without a feed event (unbuildable ones included, waived ones
     * not); `repairable` is what another run could still pick up.
     *
     * @param  array{by?: ?string, reason?: ?string}  $context  who repairs and why (kept in the event's review marker)
     * @return array{projected: int, skipped: int, unbuildable: int, remaining: int, repairable: int}
     */
    public function backfill(?string $tenantId = null, int $limit = 200, array $context = [], bool $dryRun = false, bool $retryUnbuildable = false): array
    {
        $limit = max(1, min($limit, self::MAX_REPAIR_BATCH));
        $result = ['projected' => 0, 'skipped' => 0, 'unbuildable' => 0, 'remaining' => 0, 'repairable' => 0];

        $missing = fn () => $this->repairable($tenantId, $retryUnbuildable);

        $open = fn () => IntegrationOutboxEvent::query()->withoutGlobalScopes()
            ->whereIn('event_type', AccountingFeedSnapshotBuilder::EVENT_TYPES)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('accounting_feed_events')->whereColumn('accounting_feed_events.event_id', 'integration_outbox_events.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('accounting_feed_projection_issues as i')->whereColumn('i.outbox_event_id', 'integration_outbox_events.id')->where('i.resolution', 'waived'));

        if ($dryRun) {
            $result['remaining'] = $open()->count();
            $result['repairable'] = $missing()->count();

            return $result;
        }

        foreach ($missing()->orderBy('created_at')->orderBy('id')->limit($limit)->get() as $row) {
            try {
                $event = DB::transaction(fn () => $this->project($row, self::BASIS_BACKFILL, [
                    'required' => true,
                    'reason' => self::BASIS_BACKFILL,
                    'state' => 'QUARANTINED',
                    'note' => 'Rebuilt from the current state of its source after the original projection was missed; review before booking.',
                    'repaired_by' => $context['by'] ?? null,
                    'repair_reason' => $context['reason'] ?? null,
                ]));
            } catch (UniqueConstraintViolationException) {
                $event = null; // projected concurrently by another process
            }

            if ($event) {
                $result['projected']++;
                $this->resolveIssue($row, 'projected', $context['by'] ?? null);
            } elseif (AccountingFeedEvent::query()->withoutGlobalScopes()->where('event_id', $row->id)->exists()) {
                $result['skipped']++;
            } else {
                $result['unbuildable']++;
                $this->noteIssue($row, 'source_missing', 'The source rows of this fact no longer exist; waive it on purpose or restore them.');
            }
        }

        $result['remaining'] = $open()->count();
        $result['repairable'] = $missing()->count();

        return $result;
    }

    /**
     * Outbox facts of a feed event type that have no feed event and that a repair may pick up (not waived; not known to be
     * unbuildable unless `$retryUnbuildable`).
     *
     * @return Builder<IntegrationOutboxEvent>
     */
    public function repairable(?string $tenantId = null, bool $retryUnbuildable = false)
    {
        return IntegrationOutboxEvent::query()->withoutGlobalScopes()
            ->whereIn('event_type', AccountingFeedSnapshotBuilder::EVENT_TYPES)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('accounting_feed_events')->whereColumn('accounting_feed_events.event_id', 'integration_outbox_events.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('accounting_feed_projection_issues as i')->whereColumn('i.outbox_event_id', 'integration_outbox_events.id')
                ->where(function ($w) use ($retryUnbuildable): void {
                    $w->where('i.resolution', 'waived');
                    if (! $retryUnbuildable) {
                        $w->orWhere(fn ($open) => $open->whereNull('i.resolved_at')->where('i.reason', 'source_missing'));
                    }
                }));
    }

    /** Records (or updates) the visible issue of one outbox fact without a feed event. */
    public function noteIssue(IntegrationOutboxEvent $row, string $reason, ?string $detail = null): void
    {
        $now = now();
        DB::table('accounting_feed_projection_issues')->upsert([[
            'outbox_event_id' => $row->id, 'tenant_id' => $row->tenant_id, 'event_type' => $row->event_type, 'reason' => $reason,
            'detail' => $detail, 'attempts' => 1, 'first_seen_at' => $now, 'last_seen_at' => $now,
        ]], ['outbox_event_id'], ['reason', 'detail', 'last_seen_at', 'attempts' => DB::raw('accounting_feed_projection_issues.attempts + 1')]);
    }

    private function resolveIssue(IntegrationOutboxEvent $row, string $resolution, ?string $by): void
    {
        DB::table('accounting_feed_projection_issues')->where('outbox_event_id', $row->id)->whereNull('resolved_at')
            ->update(['resolved_at' => now(), 'resolution' => $resolution, 'resolved_by' => $by]);
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
