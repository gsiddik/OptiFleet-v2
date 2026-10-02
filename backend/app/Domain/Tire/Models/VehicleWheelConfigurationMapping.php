<?php

namespace App\Domain\Tire\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One assignment period of a vehicle to a specific wheel configuration version. ACTIVE = current
 * assignment (at most one per vehicle); ENDED rows are the mapping history.
 */
class VehicleWheelConfigurationMapping extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_ENDED = 'ENDED';

    public const END_UNMAPPED = 'UNMAPPED';

    public const END_VERSION_UPDATED = 'VERSION_UPDATED';

    protected $fillable = [
        'tenant_id', 'vehicle_id', 'wheel_configuration_master_id', 'wheel_configuration_version_id', 'status',
        'mapped_at', 'mapped_by', 'ended_at', 'ended_by', 'end_reason', 'previous_mapping_id',
    ];

    protected function casts(): array
    {
        return ['mapped_at' => 'datetime', 'ended_at' => 'datetime'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(WheelConfigurationMaster::class, 'wheel_configuration_master_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(WheelConfigurationVersion::class, 'wheel_configuration_version_id');
    }
}
