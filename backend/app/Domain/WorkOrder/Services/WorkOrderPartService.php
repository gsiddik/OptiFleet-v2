<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Inventory\Services\StockReservationService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use App\Domain\WorkOrder\Models\WorkOrderPartReturnEvidence;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use Illuminate\Support\Facades\DB;

/**
 * Section 7/8/9/18/45: extends Phase 3's planned-parts line with the
 * Reservation -> Issue -> Consumption/Return lifecycle. Every stock
 * mutation still happens inside InventoryService/StockReservationService —
 * this class only orchestrates the WorkOrderPlannedPart bookkeeping
 * (status, quantities, cost snapshot) around those calls.
 */
class WorkOrderPartService
{
    /**
     * G-14: every return must declare a condition; only UNUSED_NEW ever reaches available
     * stock. "Improvement OptiFleet - Maintenance Request dan Work Order": the Return popup's
     * Condition dropdown offers "New Good"/"New Faulty" for a never-installed return and "Used
     * Good"/"Used Faulty" for one that was — UNUSED_FAULTY added alongside UNUSED_NEW,
     * following USED_FAULTY's own PENDING_INSPECTION (never-immediate-restock) handling below.
     */
    public const CONDITIONS = ['UNUSED_NEW', 'UNUSED_FAULTY', 'USED_GOOD', 'USED_FAULTY'];

    public function __construct(
        private readonly StockReservationService $reservations,
        private readonly InventoryService $inventory,
        private readonly PreferredWarehouseResolver $resolver,
        private readonly WorkOrderExecutionService $execution,
    ) {}

    public function reserve(WorkOrderPlannedPart $part, ?string $warehouseId, ?float $quantity, ?string $userId): WorkOrderPlannedPart
    {
        if (! $part->product_id) {
            throw new WorkOrderException('This planned part has no catalog product linked — cannot reserve stock for it.');
        }

        return DB::transaction(function () use ($part, $warehouseId, $quantity, $userId) {
            $workOrder = WorkOrder::query()->findOrFail($part->work_order_id);
            $this->execution->assertExecutable($workOrder);
            $warehouse = $warehouseId
                ? Warehouse::query()->findOrFail($warehouseId)
                : ($part->warehouse_id ? Warehouse::query()->find($part->warehouse_id) : $this->resolver->resolve($workOrder));

            if (! $warehouse) {
                throw new WorkOrderException('No warehouse could be resolved for this reservation — specify one explicitly.');
            }
            abort_unless($warehouse->tenant_id === $workOrder->tenant_id, 404);

            $product = Product::query()->findOrFail($part->product_id);
            $toRequest = $quantity ?? ((float) $part->planned_quantity - (float) $part->reserved_quantity);
            if ($toRequest <= 0) {
                throw new WorkOrderException('Nothing left to reserve for this planned part.');
            }

            $item = $this->reservations->reserveItem($workOrder, $warehouse, $product, $toRequest, $part->id, $userId);

            // reserved_quantity mirrors the reservation item's running total for this part.
            $part->update(['warehouse_id' => $warehouse->id, 'reserved_quantity' => $item->reserved_quantity]);

            $this->recomputeStatus($part->fresh());

            return $part->fresh();
        });
    }

    public function issue(WorkOrderPlannedPart $part, ?float $quantity, ?string $userId): WorkOrderPlannedPart
    {
        if (! $part->product_id || ! $part->warehouse_id) {
            throw new WorkOrderException('This planned part has no product/warehouse resolved — reserve it first.');
        }

        return DB::transaction(function () use ($part, $quantity, $userId) {
            $workOrder = WorkOrder::query()->findOrFail($part->work_order_id);
            $this->execution->assertExecutable($workOrder);
            $warehouse = Warehouse::query()->findOrFail($part->warehouse_id);
            $product = Product::query()->findOrFail($part->product_id);
            $toIssue = $quantity ?? ((float) $part->planned_quantity - (float) $part->issued_quantity);
            if ($toIssue <= 0) {
                throw new WorkOrderException('Nothing left to issue for this planned part.');
            }

            $result = $this->inventory->issue($warehouse, $product, $toIssue, WorkOrderPlannedPart::class, $part->id, $userId);

            $newIssuedTotal = (float) $part->issued_quantity + $toIssue;
            $newReservedTotal = max(0, (float) $part->reserved_quantity - $toIssue);
            $priorTotalCost = (float) ($part->total_cost ?? 0);

            $part->update([
                'issued_quantity' => $newIssuedTotal,
                'reserved_quantity' => $newReservedTotal,
                'unit_cost_at_issue' => $result['unit_cost'],
                'total_cost' => $priorTotalCost + $result['total_cost'],
            ]);

            $this->recomputeStatus($part->fresh());

            return $part->fresh();
        });
    }

    /**
     * G-14/G-18: condition is mandatory — a return with no condition
     * decision can never reach this method's UNUSED_NEW branch, and
     * therefore can never reach available stock. The planned-part row is
     * locked for update *inside* the same transaction that checks
     * outstandingIssued() and writes the increment, so two concurrent
     * returns for the same part serialize on that lock instead of both
     * reading a stale outstanding-issued snapshot and double-crediting
     * stock (the TOCTOU race this closes).
     */
    /** @param array<string> $evidenceIds Not-yet-linked WorkOrderPartReturnEvidence ids uploaded for this planned part. */
    public function returnPart(WorkOrderPlannedPart $part, float $quantity, string $condition, ?string $userId, ?string $reason = null, ?string $evidence = null, array $evidenceIds = []): WorkOrderPlannedPart
    {
        if ($quantity <= 0) {
            throw new WorkOrderException('Return quantity must be positive.');
        }
        if (! in_array($condition, self::CONDITIONS, true)) {
            throw new WorkOrderException('Return condition must be one of: '.implode(', ', self::CONDITIONS).'.');
        }

        return DB::transaction(function () use ($part, $quantity, $condition, $userId, $reason, $evidence, $evidenceIds) {
            $locked = WorkOrderPlannedPart::query()->lockForUpdate()->findOrFail($part->id);

            // Business rule: a Consumed line is material already used in the Work Order —
            // it can never be returned through Issuance & Return (only the still-unconsumed
            // remainder of a partially consumed line is returnable). Components physically
            // removed from the unit are returned through Removed Components instead.
            if ($locked->status === 'CONSUMED' || $locked->returnableQuantity() <= 0) {
                throw new WorkOrderException('Consumed parts cannot be returned through Issuance & Return. Use Removed Components to return components removed from the unit.');
            }
            if ($quantity > $locked->returnableQuantity()) {
                throw new WorkOrderException('Cannot return more than the returnable quantity ('.$locked->returnableQuantity().'): consumed quantity is never returnable.');
            }

            $workOrder = WorkOrder::query()->findOrFail($locked->work_order_id);
            $this->execution->assertExecutable($workOrder);
            $warehouse = Warehouse::query()->findOrFail($locked->warehouse_id);
            $product = Product::query()->findOrFail($locked->product_id);

            $stockMovementId = null;
            $dispositionStatus = 'PENDING_INSPECTION';

            if ($condition === 'UNUSED_NEW') {
                // Only a UNUSED_NEW ("New Good") return ever restores available stock.
                $this->inventory->returnStock($warehouse, $product, $quantity, WorkOrderPlannedPart::class, $locked->id, $userId, $reason);
                $stockMovementId = StockMovement::query()
                    ->where('reference_type', WorkOrderPlannedPart::class)
                    ->where('reference_id', $locked->id)
                    ->where('movement_type', 'RETURN')
                    ->latest('occurred_at')
                    ->value('id');
                $dispositionStatus = 'RESTOCKED';
            }
            // UNUSED_FAULTY / USED_GOOD / USED_FAULTY: deliberately never call
            // inventory->returnStock() here — the part leaves the Work Order, but the physical
            // stock stays out of quantity_on_hand until the Used Sparepart Processing workflow
            // inspects it.

            $return = WorkOrderPartReturn::query()->create([
                'tenant_id' => $locked->tenant_id,
                'work_order_planned_part_id' => $locked->id,
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
                'quantity' => $quantity,
                'condition' => $condition,
                'disposition_status' => $dispositionStatus,
                'stock_movement_id' => $stockMovementId,
                'returned_by' => $userId,
                'reason' => $reason,
                'evidence' => $evidence,
            ]);

            if ($evidenceIds !== []) {
                WorkOrderPartReturnEvidence::query()
                    ->where('work_order_planned_part_id', $locked->id)
                    ->whereNull('work_order_part_return_id')
                    ->whereIn('id', $evidenceIds)
                    ->update(['work_order_part_return_id' => $return->id]);
            }

            $locked->increment('returned_quantity', $quantity);
            $this->recomputeStatus($locked->fresh());

            return $locked->fresh();
        });
    }

    /**
     * G-22: locks the planned-part row so a concurrent return/consume pair
     * can't both pass their outstandingIssued() check against stale data,
     * then writes an immutable CONSUME ledger entry alongside the counter
     * update (previously the counter was the only record of consumption).
     */
    public function consume(WorkOrderPlannedPart $part, ?float $quantity, ?string $userId = null): WorkOrderPlannedPart
    {
        return DB::transaction(function () use ($part, $quantity, $userId) {
            $locked = WorkOrderPlannedPart::query()->lockForUpdate()->findOrFail($part->id);

            $toConsume = $quantity ?? $locked->outstandingIssued();
            if ($toConsume <= 0) {
                throw new WorkOrderException('Nothing left to consume for this planned part.');
            }
            if ($toConsume > $locked->outstandingIssued()) {
                throw new WorkOrderException('Cannot consume more than the outstanding issued quantity.');
            }

            $warehouse = Warehouse::query()->findOrFail($locked->warehouse_id);
            $product = Product::query()->findOrFail($locked->product_id);
            $this->inventory->recordConsumption($warehouse, $product, $toConsume, WorkOrderPlannedPart::class, $locked->id, $userId);

            $locked->increment('consumed_quantity', $toConsume);
            $this->recomputeStatus($locked->fresh());

            return $locked->fresh();
        });
    }

    private function recomputeStatus(WorkOrderPlannedPart $part): void
    {
        $planned = (float) $part->planned_quantity;
        $reserved = (float) $part->reserved_quantity;
        $issued = (float) $part->issued_quantity;
        $consumed = (float) $part->consumed_quantity;
        $returned = (float) $part->returned_quantity;

        $status = match (true) {
            $issued > 0 && $returned >= $issued => 'RETURNED',
            $issued > 0 && $consumed >= $issued => 'CONSUMED',
            $issued > 0 && $issued >= $planned => 'ISSUED',
            $issued > 0 => 'PARTIALLY_ISSUED',
            $reserved > 0 && $reserved >= $planned => 'RESERVED',
            $reserved > 0 => 'PARTIALLY_RESERVED',
            default => $part->status === 'CANCELLED' ? 'CANCELLED' : 'PLANNED',
        };

        $part->update(['status' => $status]);
    }
}
