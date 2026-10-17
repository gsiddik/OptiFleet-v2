<?php

namespace App\Domain\Integration\AccountingFeed;

use App\Domain\Integration\Models\IntegrationOutboxEvent;
use Illuminate\Support\Facades\DB;

/**
 * Monitoring of missed projections (audit A17): an integration outbox fact of a feed event type that has no feed event is a financial
 * fact the consumer will never see unless someone repairs it. The outbox row is committed together with the business change, so a
 * fact without an event is a failure, not a lag; `grace` only keeps a bulk import from raising an alarm while it is still running.
 * Read only.
 */
class AccountingFeedMonitor
{
    /**
     * @return array{missed: int, unbuildable: int, oldest_missed_at: ?string, tenants: array<string, array{missed: int, unbuildable: int, oldest_missed_at: ?string}>}
     */
    public function report(?string $tenantId = null, ?int $graceMinutes = null): array
    {
        $grace = max(0, $graceMinutes ?? (int) config('optinexus.feed.missed_after_minutes'));

        $rows = IntegrationOutboxEvent::query()->withoutGlobalScopes()
            ->whereIn('integration_outbox_events.event_type', AccountingFeedSnapshotBuilder::EVENT_TYPES)
            ->when($tenantId, fn ($q) => $q->where('integration_outbox_events.tenant_id', $tenantId))
            ->where('integration_outbox_events.created_at', '<=', now()->subMinutes($grace))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('accounting_feed_events')->whereColumn('accounting_feed_events.event_id', 'integration_outbox_events.id'))
            ->leftJoin('accounting_feed_projection_issues as i', 'i.outbox_event_id', '=', 'integration_outbox_events.id')
            ->where(fn ($q) => $q->whereNull('i.resolution')->orWhere('i.resolution', '!=', 'waived'))
            ->get(['integration_outbox_events.tenant_id', 'integration_outbox_events.created_at', 'i.reason as issue_reason']);

        $tenants = [];
        foreach ($rows as $row) {
            $t = &$tenants[$row->tenant_id];
            $t ??= ['missed' => 0, 'unbuildable' => 0, 'oldest_missed_at' => null];
            $row->issue_reason === 'source_missing' ? $t['unbuildable']++ : $t['missed']++;
            $at = (string) $row->created_at;
            $t['oldest_missed_at'] = $t['oldest_missed_at'] === null || $at < $t['oldest_missed_at'] ? $at : $t['oldest_missed_at'];
            unset($t);
        }

        return [
            'missed' => array_sum(array_column($tenants, 'missed')),
            'unbuildable' => array_sum(array_column($tenants, 'unbuildable')),
            'oldest_missed_at' => $tenants === [] ? null : min(array_column($tenants, 'oldest_missed_at')),
            'tenants' => $tenants,
        ];
    }
}
