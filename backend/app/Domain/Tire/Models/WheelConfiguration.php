<?php

namespace App\Domain\Tire\Models;

use App\Domain\MasterData\Models\VehicleCategory;
use App\Domain\Shared\Concerns\BelongsToTenantOrPlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WheelConfiguration extends Model
{
    use BelongsToTenantOrPlatform, HasUuids;

    protected $fillable = ['tenant_id', 'vehicle_category_id', 'position_code', 'label', 'axle_number', 'sequence'];

    public function vehicleCategory(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class);
    }
}
