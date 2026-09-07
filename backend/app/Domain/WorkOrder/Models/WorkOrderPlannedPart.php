<?php

namespace App\Domain\WorkOrder\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderPlannedPart extends Model
{
    use HasUuids;

    protected $fillable = ['work_order_id', 'maintenance_job_id', 'product_reference', 'description', 'quantity', 'notes'];

    protected $casts = ['quantity' => 'decimal:2'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }
}
