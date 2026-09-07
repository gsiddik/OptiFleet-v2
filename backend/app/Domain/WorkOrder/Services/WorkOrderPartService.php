<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Inventory\Services\StockReservationService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\WorkOrder\Models\WorkOrder;
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

    public function returnPart(WorkOrderPlannedPart $part, float $quantity, ?string $userId, ?string $reason = null): WorkOrderPlannedPart
    {
        if ($quantity <= 0) {
            throw new WorkOrderException('Return quantity must be positive.');
        }
        if ($quantity > $part->outstandingIssued()) {
            throw new WorkOrderException('Cannot return more than the outstanding issued quantity.');
        }

        return DB::transaction(function () use ($part, $quantity, $userId, $reason) {
            $workOrder = WorkOrder::query()->findOrFail($part->work_order_id);
            $this->execution->assertExecutable($workOrder);
            $warehouse = Warehouse::query()->findOrFail($part->warehouse_id);
            $product = Product::query()->findOrFail($part->product_id);

            $this->inventory->returnStock($warehouse, $product, $quantity, WorkOrderPlannedPart::class, $part->id, $userId, $reason);

            $part->increment('returned_quantity', $quantity);
            $this->recomputeStatus($part->fresh());

            return $part->fresh();
        });
    }

    public function consume(WorkOrderPlannedPart $part, ?float $quantity): WorkOrderPlannedPart
    {
        $toConsume = $quantity ?? $part->outstandingIssued();
        if ($toConsume <= 0) {
            throw new WorkOrderException('Nothing left to consume for this planned part.');
        }
        if ($toConsume > $part->outstandingIssued()) {
            throw new WorkOrderException('Cannot consume more than the outstanding issued quantity.');
        }

        $part->increment('consumed_quantity', $toConsume);
        $this->recomputeStatus($part->fresh());

        return $part->fresh();
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
