<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Workshop;
use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class WorkOrder extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    protected $fillable = [
        'wo_number', 'numbering_configuration_version_id', 'workflow_configuration_version_id', 'tenant_id', 'branch_id', 'workshop_id', 'workspace_id', 'vehicle_id',
        'maintenance_request_id', 'maintenance_schedule_id', 'breakdown_id',
        'maintenance_type', 'priority', 'complaint', 'current_odometer', 'engine_hour',
        'target_start_at', 'target_completion_at', 'started_at', 'completed_at', 'closed_at',
        'status', 'created_by',
    ];

    protected $casts = [
        'current_odometer' => 'decimal:2',
        'engine_hour' => 'decimal:2',
        'target_start_at' => 'datetime',
        'target_completion_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Workshop::class);
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(\App\Domain\WorkOrder\Models\WorkOrderFinding::class);
    }

    public function diagnoses(): HasMany
    {
        return $this->hasMany(\App\Domain\WorkOrder\Models\WorkOrderDiagnosis::class);
    }

    public function correctiveActions(): HasMany
    {
        return $this->hasMany(\App\Domain\WorkOrder\Models\WorkOrderCorrectiveAction::class);
    }

    public function jobs(): HasMany
    {
        return $this->hasMany(\App\Domain\WorkOrder\Models\MaintenanceJob::class);
    }

    public function plannedParts(): HasMany
    {
        return $this->hasMany(\App\Domain\WorkOrder\Models\WorkOrderPlannedPart::class);
    }

    public function additionalWorks(): HasMany
    {
        return $this->hasMany(\App\Domain\WorkOrder\Models\WorkOrderAdditionalWork::class);
    }

    public function mechanicAssignments(): HasMany
    {
        return $this->hasMany(\App\Domain\Workshop\Models\WorkOrderMechanicAssignment::class);
    }

    public function roadTests(): HasMany
    {
        return $this->hasMany(\App\Domain\QualityControl\Models\RoadTest::class);
    }

    public function vehicleRelease(): HasOne
    {
        return $this->hasOne(\App\Domain\VehicleRelease\Models\VehicleRelease::class);
    }
}
