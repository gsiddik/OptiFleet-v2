<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderPartRequestItem extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'part_request_id', 'product_id', 'product_reference', 'description',
        'quantity_requested', 'quantity_approved', 'planned_part_id', 'stock_condition',
    ];

    protected $casts = [
        'quantity_requested' => 'decimal:4',
        'quantity_approved' => 'decimal:4',
    ];

    public function partRequest(): BelongsTo
    {
        return $this->belongsTo(WorkOrderPartRequest::class, 'part_request_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed(); // history stays readable
    }

    public function plannedPart(): BelongsTo
    {
        return $this->belongsTo(WorkOrderPlannedPart::class, 'planned_part_id');
    }
}
