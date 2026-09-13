<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Partner\Models\Partner;
use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\Workflow\Models\WorkflowApprovalRequest;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'po_number', 'numbering_configuration_version_id', 'workflow_configuration_version_id', 'workflow_approval_request_id', 'purchase_request_id', 'vendor_quotation_id', 'partner_id', 'delivery_warehouse_id',
        'status', 'order_date', 'expected_delivery_date', 'subtotal', 'tax_total', 'freight_cost', 'total',
        'created_by', 'approved_by', 'notes',
    ];

    protected function casts(): array
    {
        return [
            'order_date' => 'date', 'expected_delivery_date' => 'date',
            'subtotal' => 'decimal:4', 'tax_total' => 'decimal:4', 'freight_cost' => 'decimal:4', 'total' => 'decimal:4',
        ];
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function deliveryWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'delivery_warehouse_id');
    }

    public function purchaseRequest(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequest::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class);
    }

    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    public function workflowApprovalRequest(): BelongsTo
    {
        return $this->belongsTo(WorkflowApprovalRequest::class);
    }
}
