<?php

namespace App\Domain\Procurement\Models;

use App\Domain\ProductMaster\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Quantity of one Purchase Order line on a Return Order (+ its refund amount once accepted). */
class PurchaseReturnItem extends Model
{
    use HasUuids;

    protected $fillable = ['purchase_return_id', 'purchase_order_item_id', 'product_id', 'quantity', 'refund_amount'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'refund_amount' => 'decimal:4'];
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }
}
