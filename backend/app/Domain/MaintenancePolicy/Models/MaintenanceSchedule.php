<?php

namespace App\Domain\MaintenancePolicy\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceSchedule extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'vehicle_id', 'maintenance_package_id', 'package_snapshot', 'vehicle_maintenance_profile_id',
        'next_due_date', 'next_due_odometer', 'next_due_engine_hour',
        'tolerance_days', 'tolerance_odometer', 'source_policy', 'status',
        'last_completed_at', 'last_completed_odometer', 'last_completed_work_order_id',
    ];

    protected $casts = [
        'next_due_date' => 'date',
        'last_completed_at' => 'datetime',
        'package_snapshot' => 'array',
    ];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(MaintenancePackage::class, 'maintenance_package_id');
    }
}
