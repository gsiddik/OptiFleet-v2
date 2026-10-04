<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderPlannedPart extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'work_order_id', 'maintenance_job_id', 'product_id', 'warehouse_id', 'product_reference', 'description',
        'quantity', 'status', 'planned_quantity', 'reserved_quantity', 'issued_quantity', 'consumed_quantity',
        'returned_quantity', 'unit_cost_at_issue', 'total_cost', 'notes', 'stock_condition',
    ];

    /**
     * Exposed so Issuance & Return can offer Return only for genuinely returnable quantity, and
     * show the cost of what was actually consumed (see consumedTotalCost()).
     */
    protected $appends = ['returnable_quantity', 'average_unit_cost', 'consumed_total_cost'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'planned_quantity' => 'decimal:4',
            'reserved_quantity' => 'decimal:4',
            'issued_quantity' => 'decimal:4',
            'consumed_quantity' => 'decimal:4',
            'returned_quantity' => 'decimal:4',
            'unit_cost_at_issue' => 'decimal:4',
            'total_cost' => 'decimal:4',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(WorkOrderPartReturn::class, 'work_order_planned_part_id');
    }

    public function returnEvidence(): HasMany
    {
        return $this->hasMany(WorkOrderPartReturnEvidence::class, 'work_order_planned_part_id');
    }

    /** Quantity still issued-but-not-consumed-or-returned — the ceiling for a return. */
    /** Issued but neither consumed nor already returned — the only quantity Issuance & Return may return. */
    public function returnableQuantity(): float
    {
        return $this->status === 'CONSUMED' ? 0.0 : max(0.0, $this->outstandingIssued());
    }

    protected function getReturnableQuantityAttribute(): float
    {
        return $this->returnableQuantity();
    }

    /**
     * Unit cost of this line: the issue-time cost snapshot averaged over the issued quantity
     * (several issues may have been posted at different average costs). Null until issued.
     */
    public function averageUnitCost(): ?string
    {
        if ($this->total_cost === null || ! BigDecimal::of((string) ($this->issued_quantity ?? 0))->isPositive()) {
            return null;
        }

        return (string) BigDecimal::of((string) $this->total_cost)->dividedBy((string) $this->issued_quantity, 4, RoundingMode::HALF_UP);
    }

    /**
     * Business rule: a Work Order line costs what was finally CONSUMED — Consumed Qty × Unit Cost,
     * 2 decimals half-up. Returned (and still outstanding) quantity never counts. The stored
     * `total_cost` stays the issue-time snapshot used by analytics (owner decision).
     */
    public function consumedTotalCost(): ?string
    {
        $unitCost = $this->averageUnitCost();

        return $unitCost === null ? null : (string) BigDecimal::of((string) ($this->consumed_quantity ?? 0))
            ->multipliedBy($unitCost)->toScale(2, RoundingMode::HALF_UP);
    }

    protected function getAverageUnitCostAttribute(): ?string
    {
        return $this->averageUnitCost();
    }

    protected function getConsumedTotalCostAttribute(): ?string
    {
        return $this->consumedTotalCost();
    }

    public function outstandingIssued(): float
    {
        return (float) $this->issued_quantity - (float) $this->consumed_quantity - (float) $this->returned_quantity;
    }
}
