<?php

namespace App\Domain\ComponentAsset\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ComponentInstallation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'component_asset_id', 'vehicle_id', 'position_location', 'installation_odometer', 'installed_at', 'work_order_id', 'performed_by', 'removed_at'];

    protected function casts(): array
    {
        return ['installation_odometer' => 'decimal:2', 'installed_at' => 'datetime', 'removed_at' => 'datetime'];
    }

    public function componentAsset(): BelongsTo
    {
        return $this->belongsTo(ComponentAsset::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
