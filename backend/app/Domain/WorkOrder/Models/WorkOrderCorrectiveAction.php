<?php

namespace App\Domain\WorkOrder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderCorrectiveAction extends Model
{
    use HasUuids;

    protected $fillable = ['work_order_id', 'work_order_diagnosis_id', 'action_description', 'status', 'performed_by'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function diagnosis(): BelongsTo
    {
        return $this->belongsTo(WorkOrderDiagnosis::class, 'work_order_diagnosis_id');
    }
}
