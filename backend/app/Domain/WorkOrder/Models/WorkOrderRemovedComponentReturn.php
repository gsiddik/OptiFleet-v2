<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderRemovedComponentReturn extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'work_order_removed_component_id', 'warehouse_id', 'quantity',
        'stock_movement_id', 'reason', 'returned_by',
    ];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    public function removedComponent(): BelongsTo
    {
        return $this->belongsTo(WorkOrderRemovedComponent::class, 'work_order_removed_component_id');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
