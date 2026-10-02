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

    public const STATUS_ACTIVE = 'ACTIVE';

    /** Left the configuration in a later version; kept because installation/rotation history references its code. */
    public const STATUS_RETIRED = 'RETIRED';

    protected $fillable = [
        'tenant_id', 'vehicle_category_id', 'position_code', 'label', 'axle_number', 'sequence',
        'status', 'retired_at', 'introduced_in_version_id', 'retired_in_version_id',
        'position_group', 'axle_in_group', 'side', 'wheel_index',
    ];

    protected $attributes = ['status' => self::STATUS_ACTIVE];

    protected function casts(): array
    {
        return ['retired_at' => 'datetime'];
    }

    public function vehicleCategory(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class);
    }
}
