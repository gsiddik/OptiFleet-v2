<?php

namespace App\Domain\ProductMaster\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\Shared\Concerns\BelongsToTenantOrPlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use Auditable, BelongsToTenantOrPlatform, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'code', 'sku', 'name', 'product_category_id', 'product_type', 'uom_id',
        'brand', 'manufacturer_part_number', 'description', 'reference_tread_depth_mm',
        'track_serial_number', 'track_batch', 'is_system', 'status',
    ];

    protected function casts(): array
    {
        return [
            'track_serial_number' => 'boolean', 'track_batch' => 'boolean', 'is_system' => 'boolean',
            'reference_tread_depth_mm' => 'decimal:2',
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

    public function componentGroups(): BelongsToMany
    {
        return $this->belongsToMany(ComponentGroup::class, 'product_component_groups')->withTimestamps();
    }

    public function compatibilities(): HasMany
    {
        return $this->hasMany(ProductCompatibility::class);
    }
}
