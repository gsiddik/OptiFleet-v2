<?php

namespace App\Domain\Tire\Models;

use App\Domain\ProductMaster\Models\Product;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductRimSpec extends Model
{
    protected $table = 'product_rims';

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'product_id', 'model', 'rim_type', 'diameter_inch', 'width_inch', 'bolt_holes', 'pcd_mm',
        'center_bore_mm', 'offset_mm', 'material', 'max_load_kg', 'compatible_tire_sizes',
    ];

    protected function casts(): array
    {
        return [
            'compatible_tire_sizes' => 'array',
            'diameter_inch' => 'decimal:2',
            'width_inch' => 'decimal:2',
            'pcd_mm' => 'decimal:2',
            'center_bore_mm' => 'decimal:2',
            'offset_mm' => 'decimal:2',
            'max_load_kg' => 'decimal:2',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
