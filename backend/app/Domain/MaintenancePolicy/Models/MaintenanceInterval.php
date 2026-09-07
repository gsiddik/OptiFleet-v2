<?php

namespace App\Domain\MaintenancePolicy\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceInterval extends Model
{
    use HasUuids;

    protected $fillable = [
        'maintenance_package_id', 'trigger_type', 'odometer_km', 'engine_hours',
        'calendar_days', 'months', 'tolerance_km', 'tolerance_days', 'condition_notes',
    ];

    public function package(): BelongsTo
    {
        return $this->belongsTo(MaintenancePackage::class, 'maintenance_package_id');
    }
}
