<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderPlannedPart extends Model
{
    use HasUuids;

    protected $fillable = [
        'work_order_id', 'maintenance_job_id', 'product_id', 'warehouse_id', 'product_reference', 'description',
        'quantity', 'status', 'planned_quantity', 'reserved_quantity', 'issued_quantity', 'consumed_quantity',
        'returned_quantity', 'unit_cost_at_issue', 'total_cost', 'notes',
    ];

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

    /** Quantity still issued-but-not-consumed-or-returned — the ceiling for a return. */
    public function outstandingIssued(): float
    {
        return (float) $this->issued_quantity - (float) $this->consumed_quantity - (float) $this->returned_quantity;
    }
}
