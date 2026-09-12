<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderPartReturn extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    /** G-15: disposition outcomes a FINALIZED row can land on. */
    public const DISPOSITIONS = ['REPAIR', 'REUSE', 'QUARANTINE', 'SCRAP', 'SELL_ELIGIBLE'];

    protected $fillable = [
        'tenant_id', 'work_order_planned_part_id', 'warehouse_id', 'product_id', 'quantity',
        'condition', 'disposition_status', 'stock_movement_id', 'returned_by', 'reason',
        'workflow_configuration_version_id', 'accepted_quantity', 'inspected_by', 'inspected_at',
        'inspection_notes', 'disposition', 'disposition_reason', 'proposed_by',
        'workflow_approval_request_id', 'finalized_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'accepted_quantity' => 'decimal:4',
            'inspected_at' => 'datetime',
            'finalized_at' => 'datetime',
        ];
    }

    public function plannedPart(): BelongsTo
    {
        return $this->belongsTo(WorkOrderPlannedPart::class, 'work_order_planned_part_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
