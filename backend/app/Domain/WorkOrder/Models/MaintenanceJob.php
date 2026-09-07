<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MaintenanceJob extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'work_order_id', 'component_group_id', 'service_item', 'description',
        'estimated_hours', 'actual_hours', 'status', 'assigned_mechanic',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'estimated_hours' => 'decimal:2',
        'actual_hours' => 'decimal:2',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function laborLogs(): HasMany
    {
        return $this->hasMany(\App\Domain\Workshop\Models\WorkOrderLaborLog::class, 'maintenance_job_id');
    }
}
