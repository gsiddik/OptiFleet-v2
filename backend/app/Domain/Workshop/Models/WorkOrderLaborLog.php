<?php

namespace App\Domain\Workshop\Models;

use App\Domain\WorkOrder\Models\MaintenanceJob;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderLaborLog extends Model
{
    use HasUuids;

    protected $fillable = [
        'maintenance_job_id', 'worker_id', 'status', 'started_at',
        'paused_duration_minutes', 'last_paused_at', 'completed_at', 'actual_minutes',
    ];

    protected $casts = ['started_at' => 'datetime', 'last_paused_at' => 'datetime', 'completed_at' => 'datetime'];

    public function job(): BelongsTo
    {
        return $this->belongsTo(MaintenanceJob::class, 'maintenance_job_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }
}
