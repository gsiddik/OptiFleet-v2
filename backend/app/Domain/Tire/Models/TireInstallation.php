<?php

namespace App\Domain\Tire\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TireInstallation extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'tire_id', 'vehicle_id', 'wheel_position', 'installed_at', 'installation_odometer', 'work_order_id', 'performed_by', 'removed_at'];

    protected function casts(): array
    {
        return ['installed_at' => 'datetime', 'installation_odometer' => 'decimal:2', 'removed_at' => 'datetime'];
    }

    public function tire(): BelongsTo
    {
        return $this->belongsTo(Tire::class);
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
