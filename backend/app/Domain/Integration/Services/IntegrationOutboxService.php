<?php

namespace App\Domain\Integration\Services;

use App\Domain\Integration\Models\IntegrationOutboxEvent;

/**
 * R1 §10: writes tenant-scoped, idempotent, retry-safe integration events.
 * Must always be called from inside the caller's own DB::transaction() so
 * the outbox row commits atomically with the domain state change it
 * describes (all-or-nothing, matching R1's own transactional requirement
 * for the BILLED/PAID transitions).
 *
 * Idempotency: `$aggregateId` must be the id of a row that is itself
 * created exactly once for the occurrence being recorded (an invoice, a
 * payment, a decided correction/cancellation) — combined with the unique
 * (tenant_id, event_type, aggregate_id) index, calling record() twice for
 * the same occurrence can never create a duplicate event row.
 *
 * No connector consumes these rows yet — every row this service creates
 * stays PENDING. Delivery is out of scope until an approved accounting
 * integration contract exists (ACCOUNTING_INTEGRATION_ENABLED stays false
 * — see config/services.php and .env.example).
 */
class IntegrationOutboxService
{
    public function record(
        string $tenantId,
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $correlation,
        array $payload,
    ): IntegrationOutboxEvent {
        return IntegrationOutboxEvent::query()->firstOrCreate(
            ['tenant_id' => $tenantId, 'event_type' => $eventType, 'aggregate_id' => $aggregateId],
            [
                'aggregate_type' => $aggregateType,
                'schema_version' => 1,
                'correlation' => $correlation,
                'payload' => $payload,
                'status' => 'PENDING',
            ],
        );
    }
}
