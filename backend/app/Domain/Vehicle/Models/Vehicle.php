<?php

namespace App\Domain\Vehicle\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\MasterData\Models\VehicleBrand;
use App\Domain\MasterData\Models\VehicleCategory;
use App\Domain\MasterData\Models\VehicleModel as VehicleModelMaster;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Workshop;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Vehicle extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = [
        'tenant_id', 'branch_id', 'default_workshop_id', 'vehicle_category_id',
        'brand', 'vehicle_brand_id', 'model', 'vehicle_model_id', 'vehicle_type', 'registration_number', 'vin', 'chassis_number',
        'engine_number', 'year', 'purchase_month', 'purchase_year', 'fuel_type', 'transmission_type',
        'current_odometer', 'engine_hour', 'status', 'operational_status',
        'color', 'doors', 'seats', 'length_mm', 'width_mm', 'height_mm',
        'fuel_tank_capacity_liters', 'engine_capacity_cc', 'suspension_type', 'axle_count',
        'empty_weight_kg', 'load_weight_kg', 'wheel_count', 'photo_url',
        'photo_disk', 'photo_path', 'photo_mime_type', 'photo_size',
    ];

    protected $hidden = ['photo_disk', 'photo_path'];

    protected $appends = ['photo_available'];

    protected $casts = [
        'current_odometer' => 'decimal:2',
        'engine_hour' => 'decimal:2',
        'length_mm' => 'decimal:1',
        'width_mm' => 'decimal:1',
        'height_mm' => 'decimal:1',
        'fuel_tank_capacity_liters' => 'decimal:2',
        'engine_capacity_cc' => 'decimal:1',
        'empty_weight_kg' => 'decimal:2',
        'load_weight_kg' => 'decimal:2',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function defaultWorkshop(): BelongsTo
    {
        return $this->belongsTo(Workshop::class, 'default_workshop_id');
    }

    public function vehicleCategory(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class);
    }

    public function vehicleBrand(): BelongsTo
    {
        return $this->belongsTo(VehicleBrand::class);
    }

    public function vehicleModel(): BelongsTo
    {
        return $this->belongsTo(VehicleModelMaster::class, 'vehicle_model_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(VehicleAssignment::class)->latest('effective_from');
    }

    public function transfers(): HasMany
    {
        return $this->hasMany(VehicleTransfer::class)->latest('created_at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }

    public function componentGroupIds(): array
    {
        return $this->vehicleCategory?->componentGroups()->pluck('component_groups.id')->all() ?? [];
    }

    public function getPhotoAvailableAttribute(): bool
    {
        return $this->photo_path !== null;
    }
}
