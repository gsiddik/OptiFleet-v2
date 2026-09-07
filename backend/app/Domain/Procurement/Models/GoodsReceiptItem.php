<?php

namespace App\Domain\Procurement\Models;

use App\Domain\ProductMaster\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceiptItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'goods_receipt_id', 'purchase_order_item_id', 'product_id', 'quantity_accepted',
        'quantity_rejected', 'quantity_damaged', 'batch_number', 'serial_numbers', 'unit_cost',
    ];

    protected function casts(): array
    {
        return [
            'quantity_accepted' => 'decimal:4', 'quantity_rejected' => 'decimal:4', 'quantity_damaged' => 'decimal:4',
            'unit_cost' => 'decimal:4', 'serial_numbers' => 'array',
        ];
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
