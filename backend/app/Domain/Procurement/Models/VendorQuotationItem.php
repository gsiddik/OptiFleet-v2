<?php

namespace App\Domain\Procurement\Models;

use App\Domain\ProductMaster\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorQuotationItem extends Model
{
    use HasUuids;

    protected $fillable = ['vendor_quotation_id', 'rfq_item_id', 'product_id', 'quantity', 'unit_price', 'discount_percent', 'tax_percent', 'line_total'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4', 'unit_price' => 'decimal:4', 'discount_percent' => 'decimal:2',
            'tax_percent' => 'decimal:2', 'line_total' => 'decimal:4',
        ];
    }

    public function quotation(): BelongsTo
    {
        return $this->belongsTo(VendorQuotation::class, 'vendor_quotation_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
