<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderAdditionalWork extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'work_order_id', 'description', 'requested_by', 'status',
        'requested_at', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected $casts = ['requested_at' => 'datetime', 'decided_at' => 'datetime'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
