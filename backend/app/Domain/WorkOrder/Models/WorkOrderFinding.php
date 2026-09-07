<?php

namespace App\Domain\WorkOrder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderFinding extends Model
{
    use HasUuids;

    protected $fillable = ['work_order_id', 'component_group_id', 'severity', 'description', 'created_by'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
