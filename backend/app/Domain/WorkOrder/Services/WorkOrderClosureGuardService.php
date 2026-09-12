<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Tire\Models\TireRemoval;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;

/**
 * G-17/G-35: WorkOrderTransitionService::transition() previously checked
 * only the generic status graph, with zero awareness of planned-part or
 * Tire lifecycle state — a Work Order could reach COMPLETED/CLOSED while
 * a part was still ISSUED (never consumed or returned) or a removed Tire
 * was still mid-retread/inspection. This is the exact live-VMS-observed
 * risk (a "Done With Notes" WO concealing a required fan-belt
 * replacement) generalized into a structural guard rather than a status
 * label anyone has to remember to check.
 */
class WorkOrderClosureGuardService
{
    private const RESOLVED_PART_STATUSES = ['CONSUMED', 'RETURNED', 'CANCELLED'];

    private const UNRESOLVED_TIRE_STATUSES = ['UNDER_INSPECTION', 'RETREAD'];

    /** @throws WorkOrderException when the WO has a dependency that must resolve before COMPLETED/CLOSED. */
    public function assertClosable(WorkOrder $workOrder): void
    {
        $unresolvedPart = WorkOrderPlannedPart::query()
            ->where('work_order_id', $workOrder->id)
            ->whereNotIn('status', self::RESOLVED_PART_STATUSES)
            ->first();

        if ($unresolvedPart) {
            throw new WorkOrderException(
                "Cannot complete/close Work Order: planned part '{$unresolvedPart->description}' is still {$unresolvedPart->status} — consume, return, or cancel it first."
            );
        }

        $unresolvedRemoval = TireRemoval::query()
            ->where('work_order_id', $workOrder->id)
            ->whereHas('tire', fn ($q) => $q->whereIn('current_status', self::UNRESOLVED_TIRE_STATUSES))
            ->with('tire')
            ->first();

        if ($unresolvedRemoval) {
            $status = $unresolvedRemoval->tire->current_status;
            throw new WorkOrderException(
                "Cannot complete/close Work Order: tire serial '{$unresolvedRemoval->tire->serial_number}' removed on this Work Order is still {$status} — resolve its retread/inspection first."
            );
        }
    }
}
