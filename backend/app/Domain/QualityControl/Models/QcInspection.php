<?php

namespace App\Domain\QualityControl\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QcInspection extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'work_order_id', 'inspector_worker_id', 'status', 'started_at', 'completed_at', 'notes'];

    protected $casts = ['started_at' => 'datetime', 'completed_at' => 'datetime'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function findings(): HasMany
    {
        return $this->hasMany(QcFinding::class);
    }
}
