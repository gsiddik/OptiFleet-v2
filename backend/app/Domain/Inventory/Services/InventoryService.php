<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Notification\Services\NotificationDispatchService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Section 3-11: the single place that ever mutates warehouse_stocks. Every
 * public method wraps its own transaction, row-locks the target
 * WarehouseStock first (creating it if this is the very first movement for
 * that warehouse+product pair — see lockOrCreateStock for why a catch, not
 * just firstOrCreate, is required), and writes one immutable StockMovement
 * alongside the balance update so the balance is always reconstructable
 * from the ledger. Never let quantity_available go negative or
 * quantity_reserved exceed quantity_on_hand.
 */
class InventoryService
{
    public function __construct(private readonly NotificationDispatchService $notifications) {}

    public function reserve(Warehouse $warehouse, Product $product, float $quantity, ?string $referenceType, ?string $referenceId, ?string $userId, ?string $reason = null): array
    {
        if ($quantity <= 0) {
            throw new InventoryException('Reservation quantity must be positive.');
        }

        return DB::transaction(function () use ($warehouse, $product, $quantity, $referenceType, $referenceId, $userId, $reason) {
            $stock = $this->lockOrCreateStock($warehouse, $product);

            $available = (float) $stock->quantity_on_hand - (float) $stock->quantity_reserved;
            $toReserve = min($quantity, max($available, 0));

            if ($toReserve > 0) {
                $stock->increment('quantity_reserved', $toReserve);
                $this->writeMovement($stock, 'RESERVATION', $toReserve, null, $referenceType, $referenceId, $userId, $reason);
            }

            return ['reserved' => $toReserve, 'shortfall' => $quantity - $toReserve, 'stock' => $stock->fresh()];
        });
    }

    public function releaseReservation(Warehouse $warehouse, Product $product, float $quantity, ?string $referenceType, ?string $referenceId, ?string $userId, ?string $reason = null): WarehouseStock
    {
        if ($quantity <= 0) {
            throw new InventoryException('Release quantity must be positive.');
        }

        return DB::transaction(function () use ($warehouse, $product, $quantity, $referenceType, $referenceId, $userId, $reason) {
            $stock = $this->lockOrCreateStock($warehouse, $product);

            $release = min($quantity, (float) $stock->quantity_reserved);
            if ($release > 0) {
                $stock->decrement('quantity_reserved', $release);
                $this->writeMovement($stock, 'RELEASE_RESERVATION', $release, null, $referenceType, $referenceId, $userId, $reason);
            }

            return $stock->fresh();
        });
    }

    /**
     * Issues stock, preferring to consume an existing reservation first (so
     * quantity_reserved never goes negative) and falling back to
     * unreserved on-hand stock. Returns the unit cost snapshot to preserve
     * on the caller's own record (Section 45 — historical WO cost must
     * never move when the average cost later changes).
     */
    public function issue(Warehouse $warehouse, Product $product, float $quantity, ?string $referenceType, ?string $referenceId, ?string $userId, ?string $reason = null): array
    {
        if ($quantity <= 0) {
            throw new InventoryException('Issue quantity must be positive.');
        }

        return DB::transaction(function () use ($warehouse, $product, $quantity, $referenceType, $referenceId, $userId, $reason) {
            $stock = $this->lockOrCreateStock($warehouse, $product);

            $available = (float) $stock->quantity_on_hand;
            if ($quantity > $available) {
                throw new InventoryException("Insufficient stock: requested {$quantity}, available {$available}.");
            }

            $unitCost = (float) $stock->average_unit_cost;
            $reservedConsumed = min($quantity, (float) $stock->quantity_reserved);

            $stock->decrement('quantity_on_hand', $quantity);
            if ($reservedConsumed > 0) {
                $stock->decrement('quantity_reserved', $reservedConsumed);
            }

            $this->writeMovement($stock, 'ISSUE', $quantity, $unitCost, $referenceType, $referenceId, $userId, $reason);

            $fresh = $stock->fresh();
            $this->notifyIfLowStock($warehouse, $product, $fresh);

            return ['unit_cost' => $unitCost, 'total_cost' => round($unitCost * $quantity, 4), 'stock' => $fresh];
        });
    }

    public function returnStock(Warehouse $warehouse, Product $product, float $quantity, ?string $referenceType, ?string $referenceId, ?string $userId, ?string $reason = null): WarehouseStock
    {
        if ($quantity <= 0) {
            throw new InventoryException('Return quantity must be positive.');
        }

        return DB::transaction(function () use ($warehouse, $product, $quantity, $referenceType, $referenceId, $userId, $reason) {
            $stock = $this->lockOrCreateStock($warehouse, $product);
            $stock->increment('quantity_on_hand', $quantity);
            $this->writeMovement($stock, 'RETURN', $quantity, (float) $stock->average_unit_cost, $referenceType, $referenceId, $userId, $reason);

            return $stock->fresh();
        });
    }

    /**
     * G-22: writes an immutable CONSUME ledger entry for stock already
     * decremented at ISSUE time. Carries zero on-hand/reserved balance
     * effect by design — this is a traceability marker distinguishing
     * "issued but idle" from "issued and actually used," never a second
     * deduction (that would double-deduct stock already removed at issue).
     */
    public function recordConsumption(Warehouse $warehouse, Product $product, float $quantity, ?string $referenceType, ?string $referenceId, ?string $userId, ?string $reason = null): StockMovement
    {
        if ($quantity <= 0) {
            throw new InventoryException('Consumption quantity must be positive.');
        }

        return $this->writeZeroEffectMovement($warehouse, $product, 'CONSUME', $quantity, $referenceType, $referenceId, $userId, $reason);
    }

    /**
     * G-16: writes an immutable SALE ledger entry for a Used Sparepart
     * Processing disposition sold under Phase B/C — that quantity was
     * never added to quantity_on_hand (it left the ledger, if at all, at
     * ISSUE time and was never restocked), so this is a zero-balance-
     * effect audit/history marker, exactly like CONSUME above, not a
     * second deduction.
     */
    public function recordSale(Warehouse $warehouse, Product $product, float $quantity, ?string $referenceType, ?string $referenceId, ?string $userId, ?string $reason = null): StockMovement
    {
        if ($quantity <= 0) {
            throw new InventoryException('Sale quantity must be positive.');
        }

        return $this->writeZeroEffectMovement($warehouse, $product, 'SALE', $quantity, $referenceType, $referenceId, $userId, $reason);
    }

    private function writeZeroEffectMovement(Warehouse $warehouse, Product $product, string $movementType, float $quantity, ?string $referenceType, ?string $referenceId, ?string $userId, ?string $reason): StockMovement
    {
        return DB::transaction(function () use ($warehouse, $product, $movementType, $quantity, $referenceType, $referenceId, $userId, $reason) {
            $stock = $this->lockOrCreateStock($warehouse, $product);

            return $this->writeMovement($stock, $movementType, $quantity, (float) $stock->average_unit_cost, $referenceType, $referenceId, $userId, $reason);
        });
    }

    public function adjust(Warehouse $warehouse, Product $product, float $quantity, string $direction, ?string $userId, string $reason, ?string $referenceType = null, ?string $referenceId = null): WarehouseStock
    {
        if ($quantity <= 0) {
            throw new InventoryException('Adjustment quantity must be positive.');
        }
        if (! in_array($direction, ['PLUS', 'MINUS'], true)) {
            throw new InventoryException('Adjustment direction must be PLUS or MINUS.');
        }

        return DB::transaction(function () use ($warehouse, $product, $quantity, $direction, $userId, $reason, $referenceType, $referenceId) {
            $stock = $this->lockOrCreateStock($warehouse, $product);

            if ($direction === 'MINUS' && $quantity > (float) $stock->quantity_on_hand) {
                throw new InventoryException('Cannot adjust below zero on-hand stock.');
            }

            $type = $direction === 'PLUS' ? 'ADJUSTMENT_PLUS' : 'ADJUSTMENT_MINUS';
            $direction === 'PLUS' ? $stock->increment('quantity_on_hand', $quantity) : $stock->decrement('quantity_on_hand', $quantity);
            $this->writeMovement($stock, $type, $quantity, null, $referenceType, $referenceId, $userId, $reason);

            return $stock->fresh();
        });
    }

    /** Receives purchased/transferred-in stock, updating the moving weighted average cost. */
    public function receive(Warehouse $warehouse, Product $product, float $quantity, float $unitCost, string $movementType, ?string $referenceType, ?string $referenceId, ?string $userId, ?string $reason = null): WarehouseStock
    {
        if ($quantity <= 0) {
            throw new InventoryException('Receipt quantity must be positive.');
        }
        if (! in_array($movementType, ['OPENING', 'RECEIPT', 'TRANSFER_IN'], true)) {
            throw new InventoryException('Invalid receipt movement type.');
        }

        return DB::transaction(function () use ($warehouse, $product, $quantity, $unitCost, $movementType, $referenceType, $referenceId, $userId, $reason) {
            $stock = $this->lockOrCreateStock($warehouse, $product);

            $existingQty = (float) $stock->quantity_on_hand;
            $existingCost = (float) $stock->average_unit_cost;
            $newQty = $existingQty + $quantity;
            $newAvgCost = $newQty > 0
                ? round((($existingQty * $existingCost) + ($quantity * $unitCost)) / $newQty, 4)
                : $unitCost;

            $stock->increment('quantity_on_hand', $quantity);
            $stock->update(['average_unit_cost' => $newAvgCost]);
            $this->writeMovement($stock, $movementType, $quantity, $unitCost, $referenceType, $referenceId, $userId, $reason);

            return $stock->fresh();
        });
    }

    public function scrap(Warehouse $warehouse, Product $product, float $quantity, ?string $userId, string $reason, ?string $referenceType = null, ?string $referenceId = null): WarehouseStock
    {
        if ($quantity <= 0) {
            throw new InventoryException('Scrap quantity must be positive.');
        }

        return DB::transaction(function () use ($warehouse, $product, $quantity, $userId, $reason, $referenceType, $referenceId) {
            $stock = $this->lockOrCreateStock($warehouse, $product);
            if ($quantity > (float) $stock->quantity_on_hand) {
                throw new InventoryException('Cannot scrap more than on-hand stock.');
            }

            $stock->decrement('quantity_on_hand', $quantity);
            $this->writeMovement($stock, 'SCRAP', $quantity, null, $referenceType, $referenceId, $userId, $reason);

            return $stock->fresh();
        });
    }

    public function transferOut(Warehouse $warehouse, Product $product, float $quantity, ?string $referenceType, ?string $referenceId, ?string $userId): WarehouseStock
    {
        return DB::transaction(function () use ($warehouse, $product, $quantity, $referenceType, $referenceId, $userId) {
            $stock = $this->lockOrCreateStock($warehouse, $product);
            if ($quantity > (float) $stock->quantity_on_hand) {
                throw new InventoryException('Insufficient stock to dispatch transfer.');
            }
            $stock->decrement('quantity_on_hand', $quantity);
            $this->writeMovement($stock, 'TRANSFER_OUT', $quantity, (float) $stock->average_unit_cost, $referenceType, $referenceId, $userId, null);

            $fresh = $stock->fresh();
            $this->notifyIfLowStock($warehouse, $product, $fresh);

            return $fresh;
        });
    }

    public function postOpnameVariance(Warehouse $warehouse, Product $product, float $variance, ?string $userId, ?string $reason = null): ?WarehouseStock
    {
        if ($variance === 0.0) {
            return null;
        }

        return DB::transaction(function () use ($warehouse, $product, $variance, $userId, $reason) {
            $stock = $this->lockOrCreateStock($warehouse, $product);
            $magnitude = abs($variance);

            if ($variance < 0 && $magnitude > (float) $stock->quantity_on_hand) {
                throw new InventoryException('Stock opname variance would drive on-hand below zero.');
            }

            $variance > 0 ? $stock->increment('quantity_on_hand', $magnitude) : $stock->decrement('quantity_on_hand', $magnitude);
            $this->writeMovement($stock, 'STOCK_OPNAME', $magnitude, null, 'StockOpname', null, $userId, $reason);

            return $stock->fresh();
        });
    }

    /**
     * Locks the WarehouseStock row for update, creating a zeroed row first
     * if this is the very first movement for the pair. A plain
     * firstOrCreate is not safe here: two concurrent first-movers would
     * both see no row, both attempt create(), and the loser must fall back
     * to locking the winner's row rather than raising a raw 500 (mirrors
     * the same phantom-row fix applied to Phase 3's workspace reservation).
     */
    /**
     * Section 53: fires 'inventory.low_stock' whenever a consuming
     * movement (issue/transfer-out) leaves stock at or below its reorder
     * point — dispatchEvent() is exception-safe, so a broken notification
     * rule can never affect the stock movement that triggered this check.
     */
    private function notifyIfLowStock(Warehouse $warehouse, Product $product, WarehouseStock $stock): void
    {
        $status = $stock->reorderStatus();
        if ($status === 'HEALTHY') {
            return;
        }

        $this->notifications->dispatchEvent('inventory.low_stock', $warehouse->tenant_id, [
            'product' => ['name' => $product->name, 'sku' => $product->sku],
            'warehouse' => ['name' => $warehouse->name],
            'stock' => ['available' => $stock->quantityAvailable(), 'reorder_point' => (float) $stock->reorder_point],
            'warehouse_id' => $warehouse->id,
        ], 'warehouse_stock', $stock->id);
    }

    private function lockOrCreateStock(Warehouse $warehouse, Product $product): WarehouseStock
    {
        $stock = WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('product_id', $product->id)
            ->lockForUpdate()
            ->first();

        if ($stock) {
            return $stock;
        }

        try {
            return WarehouseStock::query()->create([
                'tenant_id' => $warehouse->tenant_id,
                'warehouse_id' => $warehouse->id,
                'product_id' => $product->id,
            ]);
        } catch (QueryException $e) {
            return WarehouseStock::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    private function writeMovement(WarehouseStock $stock, string $type, float $quantity, ?float $unitCost, ?string $referenceType, ?string $referenceId, ?string $userId, ?string $reason): StockMovement
    {
        return StockMovement::query()->create([
            'tenant_id' => $stock->tenant_id,
            'warehouse_id' => $stock->warehouse_id,
            'product_id' => $stock->product_id,
            'movement_type' => $type,
            'quantity' => $quantity,
            'unit_cost' => $unitCost,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'occurred_at' => now(),
            'created_by' => $userId,
            'reason' => $reason,
        ]);
    }
}
