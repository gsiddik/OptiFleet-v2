<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Workshop\Models\WorkOrderMechanicAssignment;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

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

    protected $appends = ['estimated_labor_cost_computed'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function laborLogs(): HasMany
    {
        return $this->hasMany(\App\Domain\Workshop\Models\WorkOrderLaborLog::class, 'maintenance_job_id');
    }

    /**
     * The currently-active PRIMARY mechanic assignment for this job, if any.
     * A plain HasOne (not ofMany/latestOfMany — those generate a MAX(id)
     * tie-break subquery, and Postgres has no MAX() for uuid primary keys)
     * is sufficient because MechanicAssignmentService::assign() already
     * unassigns any previous PRIMARY before creating a new one, so at most
     * one row can ever match role=PRIMARY AND unassigned_at IS NULL.
     */
    public function primaryAssignment(): HasOne
    {
        return $this->hasOne(WorkOrderMechanicAssignment::class, 'maintenance_job_id')
            ->where('role', 'PRIMARY')
            ->whereNull('unassigned_at');
    }

    /**
     * Estimated Labor Cost = estimated_hours x the assigned PRIMARY mechanic's
     * hourly-rate snapshot (decimal-safe, HALF_UP, scale 4 — matches
     * WorkOrderService::estimate()). Null until both an estimate and a
     * PRIMARY mechanic with a known rate exist; never derived from actual
     * hours, which are a separate, later-recorded figure. Named distinctly
     * from any manually-entered figure — this Job has none of its own,
     * only the Work Order-level estimated_labor_cost does.
     */
    public function getEstimatedLaborCostComputedAttribute(): ?string
    {
        $rate = $this->primaryAssignment?->hourly_rate_snapshot;
        if ($this->estimated_hours === null || $rate === null) {
            return null;
        }

        return (string) BigDecimal::of($this->estimated_hours)
            ->multipliedBy(BigDecimal::of($rate))
            ->toScale(4, RoundingMode::HALF_UP);
    }
}
