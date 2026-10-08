<?php

namespace App\Domain\Integration\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** One telematics reading received through the OptiNexus gateway, kept whether or not it moved the odometer. */
class VehicleOdometerReading extends Model
{
    use BelongsToTenant, HasUuids;

    public const KIND_DEVICE = 'DEVICE_ODOMETER';

    public const KIND_GPS = 'GPS_DISTANCE';

    protected $fillable = [
        'tenant_id', 'vehicle_id', 'source', 'source_reading_id', 'device_ref', 'odometer_kind',
        'reported_km', 'effective_km', 'previous_odometer', 'applied', 'recorded_at',
    ];

    protected function casts(): array
    {
        return ['applied' => 'boolean', 'recorded_at' => 'datetime'];
    }
}
