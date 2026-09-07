<?php

namespace App\Domain\WorkOrder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderDiagnosis extends Model
{
    use HasUuids;

    protected $fillable = ['work_order_id', 'work_order_finding_id', 'root_cause', 'notes', 'diagnosed_by', 'diagnosed_at'];

    protected $casts = ['diagnosed_at' => 'datetime'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function finding(): BelongsTo
    {
        return $this->belongsTo(WorkOrderFinding::class, 'work_order_finding_id');
    }
}
