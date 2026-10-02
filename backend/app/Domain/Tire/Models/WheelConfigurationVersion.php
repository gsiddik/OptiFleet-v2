<?php

namespace App\Domain\Tire\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One saved state of a wheel configuration master, with its generated position list. Exactly one
 * ACTIVE version per master; earlier versions become INACTIVE and keep their positions as history.
 */
class WheelConfigurationVersion extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_INACTIVE = 'INACTIVE';

    protected $fillable = [
        'tenant_id', 'wheel_configuration_master_id', 'version_number', 'config_code', 'front_axles', 'rear_axles',
        'spare_tires', 'total_axles', 'total_wheels', 'status', 'position_diff', 'created_by', 'activated_at', 'deactivated_at',
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
            'deactivated_at' => 'datetime',
        ];
    }

    public function master(): BelongsTo
    {
        return $this->belongsTo(WheelConfigurationMaster::class, 'wheel_configuration_master_id');
    }

    public function positions(): HasMany
    {
        return $this->hasMany(WheelConfigurationVersionPosition::class)->orderBy('sequence');
    }
}
