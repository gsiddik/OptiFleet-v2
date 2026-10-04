<?php

namespace App\Domain\Tire\Services;

use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInspection;
use App\Domain\Tire\Models\TireOperation;
use App\Domain\Tire\Models\TireOperationItem;
use App\Domain\Tire\Support\TireStatus;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderPartRequest;
use App\Domain\WorkOrder\Models\WorkOrderPartRequestItem;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use App\Domain\WorkOrder\Services\WorkOrderException;

/**
 * When a Tire Operation physically happens — always inside the caller's transaction:
 *
 *   Replacement  the "Replacing With" serial when its Part Request line is Consumed in Work Order →
 *                Issuance & Return: new stock from a NEW line (stock left the warehouse at Issue,
 *                the existing inventory flow), a REUSE tire from a USED line (it left the used tire
 *                quantity at Issue, UsedTireStockService). The old tire is removed (status REMOVED: it waits in Used Tire Management for inspection
 *                before it can return to stock as Used) and the "Replacing With" serial is
 *                installed on the same position (TireService::replace).
 *   Rotation     every pair swaps positions when the Work Order is completed (TireService::swapPositions).
 *
 * Removals, installations and rotations are dated at the operation's Tire Operations date/time,
 * not at the moment the Work Order step happens, so Usage Time follows the dates the user entered.
 *   Inspection   one inspection record per selected tire when the Work Order is completed, with the
 *                measured tread depth when one was entered.
 *
 * A Work Order that is cancelled or rejected releases the operation's replacement serials and
 * cancels its still-requested part request.
 */
class TireOperationExecutionService
{
    public function __construct(
        private readonly TireService $tires,
        private readonly UsedTireStockService $usedStock,
    ) {}

    /** Called by the Work Order transition before it becomes COMPLETED; any failure aborts the completion. */
    public function applyOnWorkOrderCompleted(WorkOrder $workOrder, ?string $userId): void
    {
        $operation = TireOperation::query()->withoutGlobalScopes()->where('work_order_id', $workOrder->id)->lockForUpdate()->first();
        if (! $operation || $operation->cancelled_at !== null || $operation->applied_at !== null) {
            return;
        }
        $items = TireOperationItem::query()->withoutGlobalScopes()->where('tire_operation_id', $operation->id)->lockForUpdate()->orderBy('position_code')->get();

        match ($operation->operation_type) {
            TireOperation::ROTATION => $this->applyRotation($operation, $items, $userId),
            TireOperation::INSPECTION => $this->applyInspection($operation, $items, $userId),
            default => $this->applyReuseReplacements($operation, $items, $userId),
        };

        $operation->update(['applied_at' => now()]);
    }

    /** Called by the Work Order transition after it becomes CANCELLED or REJECTED. */
    public function releaseOnWorkOrderClosedWithoutWork(WorkOrder $workOrder, ?string $userId): void
    {
        $operation = TireOperation::query()->withoutGlobalScopes()->where('work_order_id', $workOrder->id)->first();
        if (! $operation) {
            return;
        }
        TireOperationItem::query()->withoutGlobalScopes()->where('tire_operation_id', $operation->id)
            ->whereNotNull('replacement_tire_id')->whereNull('replacement_released_at')
            ->update(['replacement_released_at' => now()]);
        WorkOrderPartRequest::query()->withoutGlobalScopes()->where('tire_operation_id', $operation->id)->where('status', 'REQUESTED')
            ->update(['status' => 'CANCELLED', 'decided_by' => $userId, 'decided_at' => now(), 'decision_note' => 'Work Order closed without work']);
    }

    /**
     * Consume of a planned part that came from a Tire Operation replacement request: installs as
     * many of that product's "Replacing With" serials as were consumed (positions in order).
     */
    public function onReplacementConsumed(WorkOrderPlannedPart $part, float $quantity, ?string $userId): void
    {
        $requestItem = WorkOrderPartRequestItem::query()->withoutGlobalScopes()->where('planned_part_id', $part->id)->first();
        $request = $requestItem ? WorkOrderPartRequest::query()->withoutGlobalScopes()->find($requestItem->part_request_id) : null;
        if (! $request || $request->tire_operation_id === null) {
            return;
        }
        if (floor($quantity) !== $quantity) {
            throw new WorkOrderException('Replacement tires are consumed per serial number: use a whole quantity.');
        }

        $operation = TireOperation::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($request->tire_operation_id);
        if ($operation->cancelled_at !== null) {
            throw new WorkOrderException('This Tire Operation was cancelled; its replacement tires cannot be installed.');
        }
        $pending = $this->pendingReplacements($operation, $part);
        if ($part->stock_condition === 'USED') {
            // Only the REUSE serials this line actually issued (not those returned).
            $issued = $this->usedStock->issuedTireIds(WorkOrderPlannedPart::class, $part->id);
            $pending = $pending->filter(fn (TireOperationItem $i) => in_array($i->replacement_tire_id, $issued, true))->values();
        }
        if ($pending->count() < (int) $quantity) {
            throw new WorkOrderException("Only {$pending->count()} replacement tire(s) of this product are still to be installed for the Tire Operation.");
        }

        foreach ($pending->take((int) $quantity) as $item) {
            $this->replaceItem($operation, $item, $userId);
        }

        $stillPending = TireOperationItem::query()->withoutGlobalScopes()->where('tire_operation_id', $operation->id)->whereNull('applied_at')->exists();
        if (! $stillPending) {
            $operation->update(['applied_at' => now()]);
        }
    }

    /**
     * REUSE serials a USED planned part issues from the used tire quantity: the operation's still
     * pending replacements of that product (positions in order).
     *
     * @return list<Tire>
     */
    public function usedReplacementTires(WorkOrderPlannedPart $part, int $quantity): array
    {
        $requestItem = WorkOrderPartRequestItem::query()->withoutGlobalScopes()->where('planned_part_id', $part->id)->first();
        $request = $requestItem ? WorkOrderPartRequest::query()->withoutGlobalScopes()->find($requestItem->part_request_id) : null;
        if (! $request || $request->tire_operation_id === null) {
            throw new WorkOrderException('A used tire line is issued only from a Tire Operation replacement request.');
        }
        $operation = TireOperation::query()->withoutGlobalScopes()->findOrFail($request->tire_operation_id);
        $issued = $this->usedStock->issuedTireIds(WorkOrderPlannedPart::class, $part->id);
        $tires = $this->pendingReplacements($operation, $part)
            ->reject(fn (TireOperationItem $i) => in_array($i->replacement_tire_id, $issued, true))
            ->map(fn (TireOperationItem $i) => Tire::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($i->replacement_tire_id))
            ->values();
        if ($tires->count() < $quantity) {
            throw new WorkOrderException("Only {$tires->count()} REUSE replacement tire(s) of this product are still to be issued for the Tire Operation.");
        }

        return $tires->take($quantity)->all();
    }

    /** Not yet installed replacements of the part's product whose serial matches the line's stock condition. */
    private function pendingReplacements(TireOperation $operation, WorkOrderPlannedPart $part)
    {
        $used = $part->stock_condition === 'USED';

        return TireOperationItem::query()->withoutGlobalScopes()->where('tire_operation_id', $operation->id)
            ->whereNull('applied_at')->whereNotNull('replacement_tire_id')
            ->whereHas('replacementTire', fn ($q) => $q->withoutGlobalScopes()->where('product_id', $part->product_id)
                ->where('current_status', $used ? '=' : '!=', TireStatus::REUSE))
            ->orderBy('position_code')->lockForUpdate()->get();
    }

    private function applyRotation(TireOperation $operation, $items, ?string $userId): void
    {
        foreach ($items->groupBy('pair_number') as $pair) {
            [$a, $b] = [$pair->first(), $pair->last()];
            $tireA = Tire::query()->withoutGlobalScopes()->findOrFail($a->tire_id);
            $tireB = Tire::query()->withoutGlobalScopes()->findOrFail($b->tire_id);
            foreach ([[$tireA, $a], [$tireB, $b]] as [$tire, $item]) {
                if ($tire->current_vehicle_id !== $operation->vehicle_id || $tire->current_position !== $item->position_code) {
                    throw new WorkOrderException("Tire {$tire->serial_number} is no longer on {$item->position_code}; the rotation cannot be completed as planned.");
                }
            }
            $this->tires->swapPositions($tireA, $tireB, (float) $operation->odometer, $operation->work_order_id, $userId, $operation->operated_at);
            TireOperationItem::query()->withoutGlobalScopes()->whereIn('id', [$a->id, $b->id])->update(['applied_at' => now()]);
        }
    }

    private function applyInspection(TireOperation $operation, $items, ?string $userId): void
    {
        foreach ($items as $item) {
            TireInspection::query()->create([
                'tenant_id' => $operation->tenant_id,
                'tire_id' => $item->tire_id,
                'tread_depth_mm' => $item->tread_depth_mm,
                'work_order_id' => $operation->work_order_id,
                'inspected_by' => $userId,
                'inspected_at' => $operation->operated_at,
            ]);
            $item->update(['applied_at' => now()]);
        }
    }

    /**
     * The old tire becomes REMOVED and waits in Used Tire Management for inspection; the
     * "Replacing With" serial (new stock or REUSE) is installed. Both are dated at the Tire
     * Operations date/time.
     */
    private function replaceItem(TireOperation $operation, TireOperationItem $item, ?string $userId): void
    {
        $old = Tire::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($item->tire_id);
        $new = Tire::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($item->replacement_tire_id);
        if ($old->current_vehicle_id !== $operation->vehicle_id || $old->current_position !== $item->position_code) {
            throw new WorkOrderException("Tire {$old->serial_number} is no longer on {$item->position_code} of this vehicle; edit the Tire Operation first.");
        }
        if (! in_array($new->current_status, TireStatus::AVAILABLE_FOR_INSTALLATION, true)) {
            throw new WorkOrderException("Serial {$new->serial_number} is {$new->current_status}, not available for installation; edit the Tire Operation and choose another serial.");
        }
        $this->tires->replace($old, $new, 'Tire Operation replacement', 'REUSE', (float) $operation->odometer, $operation->work_order_id, $userId, $operation->operated_at);
        $item->update(['applied_at' => now(), 'replacement_released_at' => now()]);
    }

    /**
     * On completion every replacement must already be consumed. Only an operation created before
     * used tires were issued through Part Requests (its request has no USED line) still installs
     * its REUSE serials now, as it was planned.
     */
    private function applyReuseReplacements(TireOperation $operation, $items, ?string $userId): void
    {
        $issuesUsed = WorkOrderPartRequestItem::query()->withoutGlobalScopes()->where('stock_condition', 'USED')
            ->whereIn('part_request_id', WorkOrderPartRequest::query()->withoutGlobalScopes()->where('tire_operation_id', $operation->id)->select('id'))
            ->exists();
        foreach ($items->filter(fn (TireOperationItem $i) => $i->applied_at === null && ! $issuesUsed) as $item) {
            $status = Tire::query()->withoutGlobalScopes()->whereKey($item->replacement_tire_id)->value('current_status');
            if ($status === TireStatus::REUSE) {
                $this->replaceItem($operation, $item, $userId);
                $item->refresh();
            }
        }
        $this->assertReplacementApplied($items->map(fn (TireOperationItem $i) => $i->fresh()));
    }

    private function assertReplacementApplied($items): void
    {
        $pending = $items->first(fn (TireOperationItem $i) => $i->applied_at === null);
        if ($pending) {
            throw new WorkOrderException("Cannot complete the Work Order: the replacement tire for {$pending->position_code} has not been consumed (installed) yet.");
        }
    }
}
