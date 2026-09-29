<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The doc's true "Planned Parts" tab — a pure budgeting line item, never
 * touching warehouse stock. See the 2026_09_28_000004 migration's
 * docblock for why this is a separate table from WorkOrderPlannedPart
 * (which backs the "Issuance & Return" tab — formerly "Request Parts", the old Planned Parts tab,
 * renamed, with its full Reserve/Issue/Consume/Return lifecycle intact).
 */
class WorkOrderPlannedPartEstimate extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    /** Only these Item Types are offered in the doc's Planned Parts Product dropdown. */
    public const ALLOWED_PRODUCT_TYPES = ['SPARE_PART', 'TIRE', 'CONSUMABLE'];

    protected $fillable = ['tenant_id', 'work_order_id', 'product_id', 'quantity', 'notes', 'created_by'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:4'];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
