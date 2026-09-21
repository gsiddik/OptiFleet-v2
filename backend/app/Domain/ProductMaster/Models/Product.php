<?php

namespace App\Domain\ProductMaster\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\Organization\Models\WarehouseBin;
use App\Domain\Shared\Concerns\BelongsToTenantOrPlatform;
use App\Domain\Tire\Models\ProductRimSpec;
use App\Domain\Tire\Models\ProductTireSpec;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use Auditable, BelongsToTenantOrPlatform, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'code', 'numbering_configuration_version_id', 'sku', 'name', 'product_category_id', 'product_type', 'uom_id',
        'default_storage_bin_id',
        'brand', 'manufacturer', 'material', 'production_year', 'weight_kg', 'length_mm', 'width_mm', 'height_mm', 'image_url',
        'manufacturer_part_number', 'description', 'reference_tread_depth_mm',
        'track_serial_number', 'track_batch', 'is_system', 'status',
    ];

    protected function casts(): array
    {
        return [
            'track_serial_number' => 'boolean', 'track_batch' => 'boolean', 'is_system' => 'boolean',
            'reference_tread_depth_mm' => 'decimal:2',
            'weight_kg' => 'decimal:3', 'length_mm' => 'decimal:1', 'width_mm' => 'decimal:1', 'height_mm' => 'decimal:1',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function uom(): BelongsTo
    {
        return $this->belongsTo(Uom::class);
    }

    public function defaultStorageBin(): BelongsTo
    {
        return $this->belongsTo(WarehouseBin::class, 'default_storage_bin_id');
    }

    public function componentGroups(): BelongsToMany
    {
        return $this->belongsToMany(ComponentGroup::class, 'product_component_groups')->withTimestamps();
    }

    public function compatibilities(): HasMany
    {
        return $this->hasMany(ProductCompatibility::class);
    }

    public function sparepartSpec(): HasOne
    {
        return $this->hasOne(ProductSparepartSpec::class);
    }

    public function consumableSpec(): HasOne
    {
        return $this->hasOne(ProductConsumableSpec::class);
    }

    public function rimSpec(): HasOne
    {
        return $this->hasOne(ProductRimSpec::class);
    }

    public function tireSpec(): HasOne
    {
        return $this->hasOne(ProductTireSpec::class);
    }

    public function toolSpec(): HasOne
    {
        return $this->hasOne(ProductToolSpec::class);
    }

    public function equipmentSpec(): HasOne
    {
        return $this->hasOne(ProductEquipmentSpec::class);
    }
}
