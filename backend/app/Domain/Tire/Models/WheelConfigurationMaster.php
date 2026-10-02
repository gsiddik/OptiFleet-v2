<?php

namespace App\Domain\Tire\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable wheel configuration template (master): an axle/wheel pattern for a Vehicle Type
 * (+ Truck Configuration Type for trucks), versioned. Identity = vehicle_type +
 * truck_configuration_type + config_code of the current version (explicit fields, never parsed
 * from the code prefix). Not linked to any vehicle — vehicle assignment is a future feature.
 */
class WheelConfigurationMaster extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    protected $fillable = [
        'tenant_id', 'vehicle_type', 'truck_configuration_type', 'config_code', 'current_version_id', 'status', 'created_by', 'updated_by',
    ];

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(WheelConfigurationVersion::class, 'current_version_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(WheelConfigurationVersion::class)->orderByDesc('version_number');
    }
}
