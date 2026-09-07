<?php

namespace App\Domain\Vehicle\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\MasterData\Models\VehicleCategory;
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
        'brand', 'model', 'vehicle_type', 'registration_number', 'vin', 'chassis_number',
        'engine_number', 'year', 'fuel_type', 'transmission_type',
        'current_odometer', 'engine_hour', 'status', 'operational_status',
    ];

    protected $casts = [
        'current_odometer' => 'decimal:2',
        'engine_hour' => 'decimal:2',
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
}
