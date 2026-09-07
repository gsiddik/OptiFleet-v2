<?php

namespace App\Domain\MasterData\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenantOrPlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ComponentGroup extends Model
{
    use Auditable, BelongsToTenantOrPlatform, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'code',
        'name',
        'parent_id',
        'sequence',
        'description',
        'is_system',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(ComponentGroup::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(ComponentGroup::class, 'parent_id');
    }

    public function vehicleCategories(): BelongsToMany
    {
        return $this->belongsToMany(
            VehicleCategory::class,
            'vehicle_category_component_groups'
        )->withTimestamps();
    }
}
