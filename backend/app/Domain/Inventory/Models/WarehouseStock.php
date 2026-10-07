<?php

namespace App\Domain\Inventory\Models;

use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WarehouseStock extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'warehouse_id', 'product_id', 'quantity_on_hand', 'quantity_reserved',
        'minimum_stock', 'maximum_stock', 'reorder_point', 'average_unit_cost', 'valuation_status', 'valuation_basis',
    ];

    protected function casts(): array
    {
        return [
            'quantity_on_hand' => 'decimal:4',
            'quantity_reserved' => 'decimal:4',
            'minimum_stock' => 'decimal:4',
            'maximum_stock' => 'decimal:4',
            'reorder_point' => 'decimal:4',
            'average_unit_cost' => 'decimal:4',
        ];
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function quantityAvailable(): float
    {
        return (float) $this->quantity_on_hand - (float) $this->quantity_reserved;
    }

    /**
     * Severity order OUT_OF_STOCK > REORDER_REQUIRED > LOW_STOCK > HEALTHY.
     * `minimum_stock` is the safety floor (breaching it is urgent);
     * `reorder_point` is the earlier trigger meant to be crossed first in a
     * correctly configured warehouse, so it only downgrades to LOW_STOCK.
     */
    public function reorderStatus(): string
    {
        $available = $this->quantityAvailable();
        if ($available <= 0) {
            return 'OUT_OF_STOCK';
        }
        if ($available <= (float) $this->minimum_stock) {
            return 'REORDER_REQUIRED';
        }
        if ($available <= (float) $this->reorder_point) {
            return 'LOW_STOCK';
        }

        return 'HEALTHY';
    }
}
