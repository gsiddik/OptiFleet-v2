<?php

namespace App\Domain\Integration\AccountingFeed;

/**
 * Read side of the accounting feed. Strictly read-only: no write of any kind happens while serving a page.
 */
class AccountingFeedService
{
    /**
     * @return array{data: array<int, array<string, mixed>>, next_cursor: string, has_more: bool}
     */
    public function page(string $tenantId, int $after, int $limit): array
    {
        $rows = AccountingFeedEvent::query()
            ->where('tenant_id', $tenantId)
            ->where('sequence', '>', $after)
            ->orderBy('sequence')
            ->limit($limit + 1)
            ->get();

        $page = $rows->take($limit);

        return [
            'data' => $page->map(fn (AccountingFeedEvent $event) => $this->envelope($event))->values()->all(),
            // With nothing new the cursor stays where the consumer already is.
            'next_cursor' => (string) ($page->last()?->sequence ?? $after),
            'has_more' => $rows->count() > $limit,
        ];
    }

    /** @return array<string, mixed> */
    private function envelope(AccountingFeedEvent $event): array
    {
        return [
            'sequence' => $event->sequence,
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
            'schema_version' => AccountingFeedSnapshotBuilder::SCHEMA_VERSION,
            'source_system' => AccountingFeedSnapshotBuilder::SOURCE_SYSTEM,
            'source_tenant_reference' => $event->tenant_id,
            'occurred_at' => $event->occurred_at->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'correlation_id' => $event->correlation_id,
            'source' => $event->source,
            'dimensions' => $event->dimensions,
            'payload' => $event->payload,
        ];
    }
}
