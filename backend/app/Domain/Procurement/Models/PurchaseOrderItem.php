<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Procurement\Services\PurchaseOrderQuantityService;
use App\Domain\ProductMaster\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    use HasUuids;

    protected $fillable = ['purchase_order_id', 'product_id', 'quantity_ordered', 'quantity_received', 'unit_price', 'discount_percent', 'tax_percent', 'line_total', 'quantity_returned', 'quantity_refunded'];

    protected function casts(): array
    {
        return [
            'quantity_ordered' => 'decimal:4', 'quantity_received' => 'decimal:4', 'unit_price' => 'decimal:4',
            'discount_percent' => 'decimal:2', 'tax_percent' => 'decimal:2', 'line_total' => 'decimal:4',
            'quantity_returned' => 'decimal:4', 'quantity_refunded' => 'decimal:4',
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

    /**
     * Remaining Receivable Qty — see PurchaseOrderQuantityService (the single source of truth):
     * Ordered − Received − Returned + Reopened, i.e. Ordered − Received − Refunded.
     */
    public function remainingQuantity(): float
    {
        return (float) (string) app(PurchaseOrderQuantityService::class)->remaining($this);
    }

    /** Received goods still held from this line (may be returned to the vendor). */
    public function returnableQuantity(): float
    {
        return (float) $this->quantity_received - (float) $this->quantity_returned;
    }
}
