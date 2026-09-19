<?php

namespace App\Domain\MaintenanceRequest\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Workshop;
use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class MaintenanceRequest extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'request_number', 'numbering_configuration_version_id', 'workflow_configuration_version_id', 'tenant_id', 'branch_id', 'workshop_id', 'vehicle_id',
        'component_group_id', 'category_id', 'source_type', 'source_inspection_id',
        'source_schedule_id', 'source_breakdown_id', 'source_recommendation_id', 'source_prediction_id',
        'priority', 'complaint',
        'requested_by', 'status', 'reviewed_by', 'reviewed_at', 'review_note', 'work_order_id',
    ];

    protected $casts = ['reviewed_at' => 'datetime'];

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

    public function componentGroup(): BelongsTo
    {
        return $this->belongsTo(ComponentGroup::class);
    }

    public function assessment(): HasOne
    {
        return $this->hasOne(MaintenanceRequestAssessment::class);
    }
}
