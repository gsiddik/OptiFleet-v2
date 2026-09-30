<?php

namespace App\Domain\MasterData\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/** Vehicle Brand "Brand Of" ↔ Vehicle Category link. */
class VehicleBrandCategory extends Pivot
{
    use HasUuids;

    protected $table = 'vehicle_brand_categories';

    public $incrementing = false;

    protected $keyType = 'string';
}
