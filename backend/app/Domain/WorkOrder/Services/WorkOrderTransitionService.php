<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Section 24: a single reusable transition table rather than status writes
 * scattered across controllers — Phase 5's configurable workflow engine is
 * meant to replace the table this class consults, not the callers of
 * transition(). Every call row-locks the WO first (Section 51/52): the
 * status guard is re-checked against the locked row, never the caller's
 * possibly-stale model instance, so two concurrent transitions on the same
 * WO can't both win.
 */
class WorkOrderTransitionService
{
    private const TRANSITIONS = [
        'DRAFT' => ['SUBMITTED', 'CANCELLED'],
        'SUBMITTED' => ['APPROVED', 'REJECTED', 'CANCELLED'],
        'APPROVED' => ['ASSIGNED', 'CANCELLED'],
        'ASSIGNED' => ['SCHEDULED', 'CANCELLED'],
        'SCHEDULED' => ['IN_PROGRESS', 'CANCELLED'],
        'IN_PROGRESS' => ['QC_PENDING', 'ON_HOLD', 'WAITING_PART', 'CANCELLED'],
        'ON_HOLD' => ['IN_PROGRESS', 'CANCELLED'],
        'WAITING_PART' => ['IN_PROGRESS', 'CANCELLED'],
        'QC_PENDING' => ['COMPLETED', 'REWORK'],
        'REWORK' => ['IN_PROGRESS'],
        'COMPLETED' => ['CLOSED'],
    ];

    public function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    public function transition(WorkOrder $workOrder, string $to, array $extra = []): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $to, $extra) {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);

            if (! $this->canTransition($locked->status, $to)) {
                throw new WorkOrderException("Cannot transition Work Order from {$locked->status} to {$to}.");
            }

            $timestamps = match ($to) {
                'IN_PROGRESS' => ['started_at' => $locked->started_at ?? now()],
                'COMPLETED' => ['completed_at' => now()],
                'CLOSED' => ['closed_at' => now()],
                default => [],
            };

            $locked->update(array_merge($timestamps, $extra, ['status' => $to]));

            return $locked->fresh();
        });
    }
}
