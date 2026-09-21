<?php

namespace App\Domain\ProductMaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProductConsumableSpec extends Model
{
    protected $table = 'product_consumables';

    protected $primaryKey = 'product_id';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'product_id', 'grade_specification', 'package_size_value', 'package_size_uom_id',
        'purchase_uom_id', 'conversion_to_base_uom', 'issue_uom_id',
        'track_expiry', 'shelf_life_value', 'shelf_life_unit', 'is_hazardous',
        'sds_file_path', 'sds_original_filename',
    ];

    protected function casts(): array
    {
        return [
            'track_expiry' => 'boolean',
            'is_hazardous' => 'boolean',
            'package_size_value' => 'decimal:3',
            'conversion_to_base_uom' => 'decimal:4',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function packageSizeUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'package_size_uom_id');
    }

    public function purchaseUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'purchase_uom_id');
    }

    public function issueUom(): BelongsTo
    {
        return $this->belongsTo(Uom::class, 'issue_uom_id');
    }

    public function storageRequirements(): BelongsToMany
    {
        return $this->belongsToMany(
            StorageRequirement::class,
            'product_consumable_storage_requirements',
            'product_id',
            'storage_requirement_id'
        )->withTimestamps();
    }
}
