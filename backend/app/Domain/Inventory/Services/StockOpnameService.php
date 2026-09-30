<?php

namespace App\Domain\Inventory\Services;

use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockOpnameItem;
use App\Domain\Invoice\Services\NumberSequenceService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Support\QuantityPolicy;
use Illuminate\Support\Facades\DB;

/**
 * Section 11: DRAFT -> COUNTING -> SUBMITTED -> APPROVED -> POSTED. Posting
 * is the only step that ever touches warehouse_stocks — every earlier
 * status is purely a paperwork/count-capture state, so nothing needs to
 * reconcile before POSTED.
 */
class StockOpnameService
{
    private const TRANSITIONS = [
        'DRAFT' => ['COUNTING'],
        'COUNTING' => ['SUBMITTED'],
        'SUBMITTED' => ['APPROVED'],
        'APPROVED' => ['POSTED'],
    ];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly NumberSequenceService $numbers,
    ) {}

    public function create(Warehouse $warehouse, ?string $userId): StockOpname
    {
        return DB::transaction(function () use ($warehouse, $userId) {
            $number = sprintf('OPN/%d/%06d', (int) now()->format('Y'), $this->numbers->next('stock_opname', (int) now()->format('Y')));

            $opname = StockOpname::query()->create([
                'tenant_id' => $warehouse->tenant_id,
                'warehouse_id' => $warehouse->id,
                'opname_number' => $number,
                'status' => 'DRAFT',
                'created_by' => $userId,
            ]);

            $stocks = DB::table('warehouse_stocks')->where('warehouse_id', $warehouse->id)->get();
            foreach ($stocks as $stock) {
                StockOpnameItem::query()->create([
                    'stock_opname_id' => $opname->id,
                    'product_id' => $stock->product_id,
                    'system_quantity' => $stock->quantity_on_hand,
                ]);
            }

            return $opname->fresh('items');
        });
    }

    public function recordCount(StockOpnameItem $item, float $physicalQuantity, ?string $notes = null): StockOpnameItem
    {
        // A physical count of counted items is a whole number; the posted variance may still be
        // fractional when it corrects historical fractional stock, so only the count is checked.
        QuantityPolicy::assertValidForProductId($item->product_id, $physicalQuantity, 'physical_quantity');
        $item->update(['physical_quantity' => $physicalQuantity, 'notes' => $notes]);

        return $item->fresh();
    }

    public function transition(StockOpname $opname, string $to): StockOpname
    {
        return DB::transaction(function () use ($opname, $to) {
            $locked = StockOpname::query()->lockForUpdate()->findOrFail($opname->id);

            if (! in_array($to, self::TRANSITIONS[$locked->status] ?? [], true)) {
                throw new InventoryException("Cannot transition Stock Opname from {$locked->status} to {$to}.");
            }

            $locked->update(['status' => $to]);

            return $locked->fresh();
        });
    }

    public function approve(StockOpname $opname, ?string $userId): StockOpname
    {
        return DB::transaction(function () use ($opname, $userId) {
            $locked = StockOpname::query()->lockForUpdate()->findOrFail($opname->id);
            if ($locked->status !== 'SUBMITTED') {
                throw new InventoryException('Only a submitted Stock Opname can be approved.');
            }
            $locked->update(['status' => 'APPROVED', 'approved_by' => $userId]);

            return $locked->fresh();
        });
    }

    public function post(StockOpname $opname, ?string $userId): StockOpname
    {
        return DB::transaction(function () use ($opname, $userId) {
            $locked = StockOpname::query()->lockForUpdate()->findOrFail($opname->id);
            if ($locked->status !== 'APPROVED') {
                throw new InventoryException('Only an approved Stock Opname can be posted.');
            }

            $warehouse = Warehouse::query()->findOrFail($locked->warehouse_id);

            foreach ($locked->items()->get() as $item) {
                if ($item->physical_quantity === null) {
                    continue;
                }
                $variance = $item->variance();
                if ($variance === 0.0) {
                    continue;
                }
                $product = Product::query()->findOrFail($item->product_id);
                $this->inventory->postOpnameVariance($warehouse, $product, $variance, $userId, "Stock opname {$locked->opname_number}");
            }

            $locked->update(['status' => 'POSTED', 'posted_at' => now()]);

            return $locked->fresh('items');
        });
    }
}
