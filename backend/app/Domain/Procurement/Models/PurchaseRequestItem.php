<?php

namespace App\Domain\Procurement\Models;

use App\Domain\ProductMaster\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequestItem extends Model
{
    use HasUuids;

    protected $fillable = ['purchase_request_id', 'product_id', 'requested_quantity', 'estimated_unit_price', 'notes'];

    protected function casts(): array
    {
        return ['requested_quantity' => 'decimal:4', 'estimated_unit_price' => 'decimal:4'];
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
