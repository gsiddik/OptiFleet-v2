<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\StockReservation;
use App\Domain\Inventory\Models\StockReservationItem;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Section 6: orchestrates StockReservation/StockReservationItem rows around
 * InventoryService::reserve(), which is what actually locks and mutates the
 * WarehouseStock row. A Work Order may reserve from more than one
 * warehouse — one StockReservation row per (work_order, warehouse) pair,
 * backed by a unique constraint.
 */
class StockReservationService
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function reserveItem(WorkOrder $workOrder, Warehouse $warehouse, Product $product, float $quantity, ?string $workOrderPlannedPartId, ?string $userId): StockReservationItem
    {
        return DB::transaction(function () use ($workOrder, $warehouse, $product, $quantity, $workOrderPlannedPartId, $userId) {
            $reservation = $this->lockOrCreateReservation($workOrder, $warehouse, $userId);

            $result = $this->inventory->reserve($warehouse, $product, $quantity, StockReservation::class, $reservation->id, $userId);

            $item = StockReservationItem::query()->where('stock_reservation_id', $reservation->id)
                ->where('product_id', $product->id)
                ->where('work_order_planned_part_id', $workOrderPlannedPartId)
                ->first();

            if ($item) {
                $item->increment('requested_quantity', $quantity);
                $item->increment('reserved_quantity', $result['reserved']);
                $item->refresh();
            } else {
                $item = StockReservationItem::query()->create([
                    'stock_reservation_id' => $reservation->id,
                    'product_id' => $product->id,
                    'work_order_planned_part_id' => $workOrderPlannedPartId,
                    'requested_quantity' => $quantity,
                    'reserved_quantity' => $result['reserved'],
                ]);
            }

            $this->recomputeStatus($reservation);

            return $item;
        });
    }

    public function releaseItem(StockReservationItem $item, ?string $userId): StockReservationItem
    {
        return DB::transaction(function () use ($item, $userId) {
            $reservation = StockReservation::query()->lockForUpdate()->findOrFail($item->stock_reservation_id);
            $warehouse = Warehouse::query()->findOrFail($reservation->warehouse_id);
            $product = Product::query()->findOrFail($item->product_id);

            $this->inventory->releaseReservation($warehouse, $product, (float) $item->reserved_quantity, StockReservation::class, $reservation->id, $userId);

            $item->update(['reserved_quantity' => 0]);
            $this->recomputeStatus($reservation);

            return $item->fresh();
        });
    }

    public function cancel(StockReservation $reservation, ?string $userId): StockReservation
    {
        return DB::transaction(function () use ($reservation, $userId) {
            $locked = StockReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if (in_array($locked->status, ['CONSUMED', 'CANCELLED'], true)) {
                throw new InventoryException("Cannot cancel a reservation already {$locked->status}.");
            }
            $warehouse = Warehouse::query()->findOrFail($locked->warehouse_id);

            foreach ($locked->items as $item) {
                if ((float) $item->reserved_quantity > 0) {
                    $product = Product::query()->findOrFail($item->product_id);
                    $this->inventory->releaseReservation($warehouse, $product, (float) $item->reserved_quantity, StockReservation::class, $locked->id, $userId);
                    $item->update(['reserved_quantity' => 0]);
                }
            }

            $locked->update(['status' => 'CANCELLED']);

            return $locked->fresh('items');
        });
    }

    private function lockOrCreateReservation(WorkOrder $workOrder, Warehouse $warehouse, ?string $userId): StockReservation
    {
        $reservation = StockReservation::query()
            ->where('work_order_id', $workOrder->id)
            ->where('warehouse_id', $warehouse->id)
            ->lockForUpdate()
            ->first();

        if ($reservation) {
            return $reservation;
        }

        try {
            return StockReservation::query()->create([
                'tenant_id' => $workOrder->tenant_id,
                'warehouse_id' => $warehouse->id,
                'work_order_id' => $workOrder->id,
                'status' => 'DRAFT',
                'created_by' => $userId,
            ]);
        } catch (QueryException $e) {
            return StockReservation::query()
                ->where('work_order_id', $workOrder->id)
                ->where('warehouse_id', $warehouse->id)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    private function recomputeStatus(StockReservation $reservation): void
    {
        $reservation->refresh();
        $items = $reservation->items()->get();

        if ($items->isEmpty()) {
            return;
        }

        $allFullyReserved = $items->every(fn ($i) => (float) $i->reserved_quantity >= (float) $i->requested_quantity);
        $anyReserved = $items->contains(fn ($i) => (float) $i->reserved_quantity > 0);

        $status = match (true) {
            $allFullyReserved => 'RESERVED',
            $anyReserved => 'PARTIALLY_RESERVED',
            default => $reservation->status === 'CANCELLED' ? 'CANCELLED' : 'DRAFT',
        };

        $reservation->update(['status' => $status]);
    }
}
