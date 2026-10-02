<?php

namespace App\Domain\Tire\Models;

use App\Domain\MasterData\Models\VehicleCategory;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One saved wheel configuration of a (tenant, vehicle category). Exactly one ACTIVE per pair;
 * earlier versions are SUPERSEDED and kept as history together with the position diff applied.
 */
class WheelConfigurationVersion extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_SUPERSEDED = 'SUPERSEDED';

    protected $fillable = [
        'tenant_id', 'vehicle_category_id', 'version_number', 'vehicle_type', 'truck_configuration_type', 'config_code',
        'front_axles', 'rear_axles', 'spare_tires', 'total_axles', 'total_wheels', 'status', 'position_diff',
        'created_by', 'activated_at', 'superseded_at',
    ];

    protected function casts(): array
    {
        return [
            'front_axles' => 'array',
            'rear_axles' => 'array',
            'position_diff' => 'array',
            'version_number' => 'integer',
            'spare_tires' => 'integer',
            'total_axles' => 'integer',
            'total_wheels' => 'integer',
            'activated_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    public function vehicleCategory(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class);
    }
}
