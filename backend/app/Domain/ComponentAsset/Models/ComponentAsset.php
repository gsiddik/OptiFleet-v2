<?php

namespace App\Domain\ComponentAsset\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ComponentAsset extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'product_id', 'component_group_id', 'serial_number', 'asset_number',
        'purchase_date', 'purchase_cost', 'current_status', 'current_vehicle_id', 'current_warehouse_id',
    ];

    protected function casts(): array
    {
        return ['purchase_date' => 'date', 'purchase_cost' => 'decimal:4'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function componentGroup(): BelongsTo
    {
        return $this->belongsTo(ComponentGroup::class)->withTrashed(); // soft-deleted groups stay resolvable on history
    }

    public function currentVehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class, 'current_vehicle_id');
    }

    public function currentWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'current_warehouse_id');
    }

    public function installations(): HasMany
    {
        return $this->hasMany(ComponentInstallation::class);
    }

    public function removals(): HasMany
    {
        return $this->hasMany(ComponentRemoval::class);
    }

    public function repairs(): HasMany
    {
        return $this->hasMany(ComponentRepair::class);
    }
}
