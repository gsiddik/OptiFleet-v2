<?php

namespace App\Domain\MaintenancePolicy\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VehicleMaintenanceProfile extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'vehicle_id', 'maintenance_package_id', 'status', 'effective_from'];

    protected $casts = ['effective_from' => 'date'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(MaintenancePackage::class, 'maintenance_package_id');
    }
}
