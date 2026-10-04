<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Support\QuantityPolicy;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Services\UsedTireStockService;
use App\Domain\Tire\Support\TireStatus;
use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use Illuminate\Support\Facades\DB;

/**
 * Returned Parts Processing: the warehouse inspects a new part returned (not used) from a
 * Work Order and validates that what physically came back matches what the Work Order
 * declared (condition and quantity).
 *
 *   PENDING_PROCESSING -> RESTOCKED    actual condition New Good: the received quantity is posted
 *                                      back to available stock (one RETURN movement)
 *                      -> QUARANTINED  actual condition New Faulty: never added to available stock
 *   QUARANTINED        -> WARRANTY_CLAIM | REPAIR | SCRAP  (route(): follow-up disposition, no stock)
 *
 * The row is locked and its status checked against NEW_PART_TRANSITIONS, so a return can be
 * processed (and restocked) only once, and a quarantined return routed only once.
 */
class ReturnProcessingService
{
    public const ACTUAL_CONDITIONS = ['UNUSED_NEW', 'UNUSED_FAULTY'];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly UsedTireStockService $usedStock,
    ) {}

    public function process(WorkOrderPartReturn $return, string $actualCondition, float $receivedQuantity, ?string $notes, string $userId): WorkOrderPartReturn
    {
        if (! in_array($actualCondition, self::ACTUAL_CONDITIONS, true)) {
            throw new WorkOrderException('Actual condition must be New Good or New Faulty.');
        }

        return DB::transaction(function () use ($return, $actualCondition, $receivedQuantity, $notes, $userId) {
            $locked = WorkOrderPartReturn::query()->lockForUpdate()->findOrFail($return->id);

            if ($locked->return_source !== WorkOrderPartReturn::SOURCE_NEW_PART) {
                throw new WorkOrderException('Only a returned new part is processed here; removed components go through Used Sparepart Processing.');
            }
            $target = $actualCondition === 'UNUSED_NEW' ? 'RESTOCKED' : 'QUARANTINED';
            if (! in_array($target, WorkOrderPartReturn::NEW_PART_TRANSITIONS[$locked->disposition_status] ?? [], true)) {
                throw new WorkOrderException("This return has already been processed ({$locked->disposition_status}).");
            }
            QuantityPolicy::assertValidForProductId($locked->product_id, $receivedQuantity, 'received_quantity');
            if ($receivedQuantity <= 0 || $receivedQuantity > (float) $locked->quantity) {
                throw new WorkOrderException('Received quantity must be greater than zero and cannot exceed the returned quantity ('.(float) $locked->quantity.').');
            }

            $matches = $actualCondition === $locked->condition && $receivedQuantity === (float) $locked->quantity;
            $stockMovementId = null;

            $part = WorkOrderPlannedPart::query()->find($locked->work_order_planned_part_id);
            if ($part?->stock_condition === 'USED') {
                if (floor($receivedQuantity) !== $receivedQuantity) {
                    throw new WorkOrderException('Used tires are returned per serial number: use a whole quantity.');
                }
                $this->processUsedTires($locked, $part, $target, (int) $receivedQuantity, $userId);
            } elseif ($target === 'RESTOCKED') {
                $warehouse = Warehouse::query()->findOrFail($locked->warehouse_id);
                $product = Product::query()->withTrashed()->findOrFail($locked->product_id);
                $this->inventory->returnStock(
                    $warehouse, $product, $receivedQuantity, WorkOrderPartReturn::class, $locked->id, $userId,
                    "Returned part {$locked->return_number} accepted after inspection",
                );
                $stockMovementId = StockMovement::query()
                    ->where('reference_type', WorkOrderPartReturn::class)->where('reference_id', $locked->id)
                    ->where('movement_type', 'RETURN')->latest('occurred_at')->value('id');
            }

            $locked->update([
                'actual_condition' => $actualCondition,
                'accepted_quantity' => $receivedQuantity,
                'inspection_result' => $matches ? 'MATCH' : 'MISMATCH',
                'inspection_notes' => $notes,
                'inspected_by' => $userId,
                'inspected_at' => now(),
                'disposition_status' => $target,
                'stock_movement_id' => $stockMovementId,
            ]);

            return $locked->fresh();
        });
    }

    /**
     * A returned USED line (REUSE tires issued for a Tire Operation and not installed): New Good
     * puts the serials back into the warehouse's used tire quantity; New Faulty puts them on HOLD
     * in that warehouse to be inspected again in Used Tire Management.
     */
    private function processUsedTires(WorkOrderPartReturn $return, WorkOrderPlannedPart $part, string $target, int $quantity, string $userId): void
    {
        $tires = Tire::query()->withoutGlobalScopes()->whereIn('id', $this->usedStock->issuedTireIds(WorkOrderPlannedPart::class, $part->id))
            ->where('current_status', TireStatus::REUSE)->whereNull('current_vehicle_id')->orderBy('serial_number')->lockForUpdate()->get();
        if ($tires->count() < $quantity) {
            throw new WorkOrderException("Only {$tires->count()} issued used tire(s) of this line are not installed.");
        }
        foreach ($tires->take($quantity) as $tire) {
            if ($target === 'RESTOCKED') {
                $this->usedStock->receive($tire, $return->warehouse_id, 'RETURN', WorkOrderPartReturn::class, $return->id, $userId, "Returned part {$return->return_number} accepted after inspection");
                $tire->update(['current_warehouse_id' => $return->warehouse_id]);
            } else {
                $tire->update(['current_status' => TireStatus::HOLD, 'current_warehouse_id' => $return->warehouse_id]);
            }
        }
    }

    /**
     * Route a quarantined (faulty) return to its follow-up disposition. Status only: the part
     * stays out of available stock; warranty claim / repair / scrap processing is a later scope.
     */
    public function route(WorkOrderPartReturn $return, string $disposition, ?string $reason, string $userId): WorkOrderPartReturn
    {
        if (! in_array($disposition, WorkOrderPartReturn::FAULTY_DISPOSITIONS, true)) {
            throw new WorkOrderException('Disposition must be Warranty Claim, Repair or Scrap.');
        }

        return DB::transaction(function () use ($return, $disposition, $reason, $userId) {
            $locked = WorkOrderPartReturn::query()->lockForUpdate()->findOrFail($return->id);

            if ($locked->return_source !== WorkOrderPartReturn::SOURCE_NEW_PART) {
                throw new WorkOrderException('Only a returned new part is routed here; removed components go through Used Sparepart Processing.');
            }
            if (! in_array($disposition, WorkOrderPartReturn::NEW_PART_TRANSITIONS[$locked->disposition_status] ?? [], true)) {
                throw new WorkOrderException("Only a quarantined return can be routed to a disposition (current status: {$locked->disposition_status}).");
            }

            $locked->update([
                'disposition_status' => $disposition,
                'disposition_reason' => $reason,
                'routed_by' => $userId,
                'routed_at' => now(),
            ]);

            return $locked->fresh();
        });
    }
}
