<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderPartReturnEvidence extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'work_order_planned_part_id', 'work_order_part_return_id',
        'disk', 'path', 'original_filename', 'mime_type', 'size', 'uploaded_by',
    ];

    public function plannedPart(): BelongsTo
    {
        return $this->belongsTo(WorkOrderPlannedPart::class, 'work_order_planned_part_id');
    }

    public function partReturn(): BelongsTo
    {
        return $this->belongsTo(WorkOrderPartReturn::class, 'work_order_part_return_id');
    }
}
