<?php

namespace App\Domain\Partner\Services;

use App\Domain\Partner\Models\Partner;
use App\Domain\Partner\Models\PartnerPerformanceEvent;

/**
 * Section 25: read-time KPI aggregation over the append-only performance
 * event log — no separate summary table to keep in sync.
 */
class PartnerPerformanceService
{
    public function record(Partner $partner, string $eventType, ?string $referenceType = null, ?string $referenceId = null, ?float $quantity = null, ?float $value = null, ?string $notes = null): PartnerPerformanceEvent
    {
        return PartnerPerformanceEvent::query()->create([
            'tenant_id' => $partner->tenant_id,
            'partner_id' => $partner->id,
            'event_type' => $eventType,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'quantity' => $quantity,
            'value' => $value,
            'occurred_at' => now(),
            'notes' => $notes,
        ]);
    }

    public function summary(Partner $partner): array
    {
        $events = $partner->performanceEvents()->get();

        $delivered = $events->whereIn('event_type', ['DELIVERY_ON_TIME', 'DELIVERY_LATE']);
        $onTime = $events->where('event_type', 'DELIVERY_ON_TIME')->count();
        $late = $events->where('event_type', 'DELIVERY_LATE')->count();
        $deliveredCount = $delivered->count();

        return [
            'purchase_orders_issued' => $events->where('event_type', 'PO_ISSUED')->count(),
            'deliveries_on_time' => $onTime,
            'deliveries_late' => $late,
            'on_time_rate' => $deliveredCount > 0 ? round($onTime / $deliveredCount * 100, 1) : null,
            'quantity_accepted' => (float) $events->where('event_type', 'GOODS_ACCEPTED')->sum('quantity'),
            'quantity_rejected' => (float) $events->where('event_type', 'GOODS_REJECTED')->sum('quantity'),
            'total_purchase_value' => (float) $events->where('event_type', 'PO_ISSUED')->sum('value'),
            'returns' => $events->where('event_type', 'RETURN')->count(),
        ];
    }
}
