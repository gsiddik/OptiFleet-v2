<?php

namespace App\Domain\Inventory\Services;

use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use Illuminate\Database\Eloquent\Builder;

/**
 * Warehouse view of used spareparts (Removed Components → Used Sparepart Processing), owner
 * decision "traceability tab". Reads the processing records only — it never creates inventory
 * rows, so one physical part is always one representation:
 *
 *   REUSABLE        approved REUSE (incl. Repair → Reuse). Its quantity was posted ONCE into the
 *                   product's regular on-hand by the approval and is issued through Part Requests.
 *   QUARANTINE      approved QUARANTINE — physically present, held, never available.
 *   REPAIR_PENDING  approved REPAIR not yet completed — physically present, not reusable.
 *
 * Quarantine and repair-pending quantities are never part of quantity_on_hand / available.
 */
class UsedSparepartAvailabilityService
{
    public const CATEGORIES = ['REUSABLE', 'QUARANTINE', 'REPAIR_PENDING'];

    public function query(): Builder
    {
        return WorkOrderPartReturn::query()
            ->whereIn('return_source', WorkOrderPartReturn::USED_SOURCES)
            ->where('disposition_status', 'FINALIZED')
            ->where(fn ($q) => $q->where('disposition', 'REUSE')
                ->orWhere('disposition', 'QUARANTINE')
                ->orWhere(fn ($r) => $r->where('disposition', 'REPAIR')->whereNull('repair_completed_at')));
    }

    public function applyCategory(Builder $query, string $category): Builder
    {
        return match ($category) {
            'REUSABLE' => $query->where('disposition', 'REUSE'),
            'QUARANTINE' => $query->where('disposition', 'QUARANTINE'),
            'REPAIR_PENDING' => $query->where('disposition', 'REPAIR')->whereNull('repair_completed_at'),
        };
    }

    public static function categoryOf(WorkOrderPartReturn $return): string
    {
        return match ($return->disposition) {
            'REUSE' => 'REUSABLE',
            'QUARANTINE' => 'QUARANTINE',
            default => 'REPAIR_PENDING',
        };
    }

    /** Quantity the record stands for: what inspection accepted, else what was returned. */
    public static function quantityOf(WorkOrderPartReturn $return): float
    {
        return (float) ($return->accepted_quantity ?? $return->quantity);
    }

    /** @return array{reusable_qty: float, quarantine_qty: float, repair_pending_qty: float} */
    public function summary(Builder $filtered): array
    {
        $totals = ['REUSABLE' => 0.0, 'QUARANTINE' => 0.0, 'REPAIR_PENDING' => 0.0];
        foreach ((clone $filtered)->get(['id', 'disposition', 'accepted_quantity', 'quantity', 'repair_completed_at']) as $row) {
            $totals[self::categoryOf($row)] += self::quantityOf($row);
        }

        return ['reusable_qty' => $totals['REUSABLE'], 'quarantine_qty' => $totals['QUARANTINE'], 'repair_pending_qty' => $totals['REPAIR_PENDING']];
    }
}
