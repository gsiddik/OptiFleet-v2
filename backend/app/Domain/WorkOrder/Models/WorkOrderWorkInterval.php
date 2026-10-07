<?php

namespace App\Domain\WorkOrder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One period of actual work on a Work Order: opened by a transition into IN_PROGRESS, closed by the
 * next transition out of it. Written only by WorkOrderTransitionService (see its migration).
 */
class WorkOrderWorkInterval extends Model
{
    use HasUuids;

    protected $fillable = ['tenant_id', 'work_order_id', 'cycle', 'started_at', 'ended_at', 'start_from_status', 'end_to_status', 'started_by', 'ended_by'];

    protected $casts = ['cycle' => 'integer', 'started_at' => 'datetime', 'ended_at' => 'datetime'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
