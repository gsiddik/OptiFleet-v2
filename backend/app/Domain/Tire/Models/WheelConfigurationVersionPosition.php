<?php

namespace App\Domain\Tire\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** A wheel position generated for a configuration version (e.g. 1FL1, 2RR2, S1). */
class WheelConfigurationVersionPosition extends Model
{
    use BelongsToTenant, HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'tenant_id', 'wheel_configuration_version_id', 'position_code', 'position_group', 'axle_in_group', 'axle_number', 'side', 'wheel_index', 'label', 'sequence',
    ];
}
