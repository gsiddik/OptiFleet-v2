<?php

namespace App\Domain\Inventory\Models;

use App\Domain\ProductMaster\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockOpnameItem extends Model
{
    use HasUuids;

    protected $fillable = ['stock_opname_id', 'product_id', 'system_quantity', 'physical_quantity', 'notes'];

    protected function casts(): array
    {
        return ['system_quantity' => 'decimal:4', 'physical_quantity' => 'decimal:4'];
    }

    public function opname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class, 'stock_opname_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variance(): float
    {
        return (float) $this->physical_quantity - (float) $this->system_quantity;
    }
}
