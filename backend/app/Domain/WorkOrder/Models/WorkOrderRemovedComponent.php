<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * The "old/removed component" domain (owner decision) — a component taken
 * OFF the vehicle when a replacement part is installed, distinct from
 * `WorkOrderPartReturn` (which only ever represents warehouse-issued
 * stock flowing back, never something that came off the vehicle). See
 * the 2026_09_28_000003 migration's docblock for the full architecture
 * rationale.
 */
class WorkOrderRemovedComponent extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const CONDITIONS = ['GOOD', 'FAULTY'];

    protected $fillable = [
        'tenant_id', 'work_order_id', 'maintenance_job_id', 'replaced_by_planned_part_id',
        'product_id', 'quantity', 'condition', 'notes', 'status', 'removed_by', 'removed_at',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4', 'removed_at' => 'datetime'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function maintenanceJob(): BelongsTo
    {
        return $this->belongsTo(MaintenanceJob::class);
    }

    public function replacedByPlannedPart(): BelongsTo
    {
        return $this->belongsTo(WorkOrderPlannedPart::class, 'replaced_by_planned_part_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function return(): HasOne
    {
        return $this->hasOne(WorkOrderRemovedComponentReturn::class);
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(WorkOrderRemovedComponentEvidence::class);
    }

    /** Quantity still awaiting return to a warehouse. */
    public function outstandingReturn(): float
    {
        return $this->status === 'RETURNED' ? 0.0 : (float) $this->quantity;
    }
}
