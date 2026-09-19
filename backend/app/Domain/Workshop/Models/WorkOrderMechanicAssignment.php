<?php

namespace App\Domain\Workshop\Models;

use App\Domain\WorkOrder\Models\MaintenanceJob;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderMechanicAssignment extends Model
{
    use HasUuids;

    protected $fillable = ['work_order_id', 'maintenance_job_id', 'worker_id', 'role', 'hourly_rate_snapshot', 'assigned_at', 'assigned_by', 'unassigned_at'];

    protected $casts = ['hourly_rate_snapshot' => 'decimal:4', 'assigned_at' => 'datetime', 'unassigned_at' => 'datetime'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(MaintenanceJob::class, 'maintenance_job_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
