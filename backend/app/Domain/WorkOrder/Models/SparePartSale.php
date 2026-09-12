<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Partner\Models\Partner;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SparePartSale extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const SALE_TYPES = ['OPERATIONAL_REUSE', 'SCRAP_MATERIAL'];

    public const BUYER_TYPES = ['PARTNER', 'EXTERNAL'];

    /** Sums against this quantity, not disposition_status, define how much of a return has been sold. */
    public const ACTIVE_STATUSES = ['DRAFT', 'PENDING_APPROVAL', 'APPROVED'];

    protected $fillable = [
        'tenant_id', 'work_order_part_return_id', 'product_id', 'warehouse_id', 'quantity',
        'sale_type', 'buyer_type', 'partner_id', 'buyer_name', 'unit_price', 'total_amount',
        'status', 'workflow_configuration_version_id', 'workflow_approval_request_id',
        'stock_movement_id', 'requested_by', 'decided_by', 'decided_at', 'rejection_reason', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit_price' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'decided_at' => 'datetime',
        ];
    }

    public function partReturn(): BelongsTo
    {
        return $this->belongsTo(WorkOrderPartReturn::class, 'work_order_part_return_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
