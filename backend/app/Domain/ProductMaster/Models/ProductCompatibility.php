<?php

namespace App\Domain\ProductMaster\Models;

use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\MasterData\Models\VehicleCategory;
use App\Domain\Shared\Concerns\BelongsToTenantOrPlatform;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductCompatibility extends Model
{
    use BelongsToTenantOrPlatform, HasUuids;

    protected $fillable = [
        'tenant_id', 'product_id', 'component_group_id', 'vehicle_category_id', 'vehicle_brand', 'vehicle_model', 'notes',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function componentGroup(): BelongsTo
    {
        return $this->belongsTo(ComponentGroup::class);
    }

    public function vehicleCategory(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class);
    }

    /** Number of non-null dimensions this rule constrains — higher wins. */
    public function specificity(): int
    {
        return (int) ($this->component_group_id !== null)
            + (int) ($this->vehicle_category_id !== null)
            + (int) ($this->vehicle_brand !== null)
            + (int) ($this->vehicle_model !== null);
    }
}
