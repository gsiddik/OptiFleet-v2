<?php

namespace App\Domain\MaintenanceRequest\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MaintenanceRequestInspectionGroup extends Model
{
    use BelongsToTenant, HasUuids;

    public const GROUP_CODES = [
        'ENGINE', 'LUBRICATION_SYSTEM', 'CLUTCH_TORQUE_CONVERTER', 'COOLING_SYSTEM',
        'FUEL_SYSTEM', 'TRANSMISSION_SYSTEM', 'EXHAUST_SYSTEM', 'STEERING_SYSTEM',
        'DRIVE_AXLE_ASSEMBLY', 'FRAME_CHASSIS', 'ELECTRICAL_SYSTEM', 'BRAKE_SYSTEM',
        'SUSPENSION_SYSTEM', 'TYRE_WHEEL',
    ];

    public const STATUSES = ['GOOD', 'ATTENTION', 'REPAIR_REQUIRED', 'CRITICAL_UNSAFE', 'NOT_APPLICABLE'];

    protected $fillable = [
        'tenant_id', 'maintenance_request_assessment_id', 'group_code', 'status', 'notes',
    ];

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(MaintenanceRequestAssessment::class, 'maintenance_request_assessment_id');
    }
}
