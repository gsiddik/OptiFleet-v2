<?php

namespace App\Domain\Integration\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A telematics device feeding a vehicle's odometer. `odometer_offset_km` is
 * null until an admin calibrates it; GPS-distance readings are held until then.
 */
class VehicleTelematicsLink extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'vehicle_id', 'source', 'device_ref', 'odometer_offset_km', 'calibrated_by', 'calibrated_at'];

    protected function casts(): array
    {
        return ['odometer_offset_km' => 'decimal:2', 'calibrated_at' => 'datetime'];
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function isCalibrated(): bool
    {
        return $this->odometer_offset_km !== null;
    }
}
