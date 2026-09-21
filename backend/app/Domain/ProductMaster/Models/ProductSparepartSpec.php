<?php

namespace App\Domain\ProductMaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductSparepartSpec extends Model
{
    protected $table = 'product_spareparts';

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'product_id', 'part_number', 'part_type', 'oem_part_number', 'alternate_part_numbers',
        'specification', 'applicable_position', 'critical_part',
        'warranty_period_value', 'warranty_period_unit', 'warranty_mileage_km',
        'shelf_life_value', 'shelf_life_unit',
    ];

    protected function casts(): array
    {
        return [
            'alternate_part_numbers' => 'array',
            'applicable_position' => 'array',
            'critical_part' => 'boolean',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
