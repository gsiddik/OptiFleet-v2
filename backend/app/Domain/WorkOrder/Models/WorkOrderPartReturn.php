<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderPartReturn extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'work_order_planned_part_id', 'warehouse_id', 'product_id', 'quantity',
        'condition', 'disposition_status', 'stock_movement_id', 'returned_by', 'reason',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
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
