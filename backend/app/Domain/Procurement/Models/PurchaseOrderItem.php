<?php

namespace App\Domain\Procurement\Models;

use App\Domain\ProductMaster\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    use HasUuids;

    protected $fillable = ['purchase_order_id', 'product_id', 'quantity_ordered', 'quantity_received', 'unit_price', 'discount_percent', 'tax_percent', 'line_total'];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:4', 'quantity_received' => 'decimal:4', 'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:2', 'tax_percent' => 'decimal:2', 'line_total' => 'decimal:4',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function remainingQuantity(): float
    {
        return (float) $this->quantity_ordered - (float) $this->quantity_received;
    }
}
