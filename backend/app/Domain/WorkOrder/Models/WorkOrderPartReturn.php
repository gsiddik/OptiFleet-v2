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
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkOrderPartReturn extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    /** G-15: disposition outcomes a FINALIZED row can land on. */
    public const DISPOSITIONS = ['REPAIR', 'REUSE', 'QUARANTINE', 'SCRAP', 'SELL_ELIGIBLE'];

    /**
     * Three separate lifecycles, never mixed:
     *  NEW_PART           issued, not used, returned from Issuance & Return -> Return / Returned Parts Processing
     *  REMOVED_COMPONENT  old component taken off the vehicle -> Used Sparepart Processing
     *  USED_PART          legacy used-condition returns already in Used Sparepart Processing
     */
    public const SOURCE_NEW_PART = 'NEW_PART';

    public const SOURCE_REMOVED_COMPONENT = 'REMOVED_COMPONENT';

    public const SOURCE_USED_PART = 'USED_PART';

    /** Sources handled by Used Sparepart Processing. */
    public const USED_SOURCES = [self::SOURCE_REMOVED_COMPONENT, self::SOURCE_USED_PART];

    /**
     * Follow-up dispositions for a faulty (QUARANTINED) returned new part. Each is an open
     * status awaiting its own processing (later scope); none of them ever puts the part back
     * into available stock.
     */
    public const FAULTY_DISPOSITIONS = ['WARRANTY_CLAIM', 'REPAIR', 'SCRAP'];

    /**
     * NEW_PART: stock is posted only when Returned Parts Processing accepts the part as good.
     * A faulty part is QUARANTINED (out of available stock) until routed to a follow-up disposition.
     */
    public const NEW_PART_TRANSITIONS = [
        'PENDING_PROCESSING' => ['RESTOCKED', 'QUARANTINED'],
        'RESTOCKED' => [],
        'QUARANTINED' => self::FAULTY_DISPOSITIONS,
        'WARRANTY_CLAIM' => [],
        'REPAIR' => [],
        'SCRAP' => [],
    ];

    protected $fillable = [
        'return_number', 'return_source', 'work_order_id', 'work_order_removed_component_id', 'actual_condition', 'inspection_result',
        'tenant_id', 'work_order_planned_part_id', 'warehouse_id', 'product_id', 'quantity',
        'condition', 'disposition_status', 'stock_movement_id', 'returned_by', 'reason', 'evidence',
        'workflow_configuration_version_id', 'accepted_quantity', 'inspected_by', 'inspected_at',
        'inspection_notes', 'inspection_evidence', 'disposition', 'disposition_reason', 'proposed_by',
        'workflow_approval_request_id', 'finalized_at', 'routed_by', 'routed_at',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'accepted_quantity' => 'decimal:4',
            'inspected_at' => 'datetime',
            'finalized_at' => 'datetime',
            'routed_at' => 'datetime',
        ];
    }

    public function plannedPart(): BelongsTo
    {
        return $this->belongsTo(WorkOrderPlannedPart::class, 'work_order_planned_part_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function removedComponent(): BelongsTo
    {
        return $this->belongsTo(WorkOrderRemovedComponent::class, 'work_order_removed_component_id');
    }

    public function returner(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'returned_by');
    }

    public function inspector(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'inspected_by');
    }

    public function router(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'routed_by');
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed(); // history stays readable
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }

    public function sales(): HasMany
    {
        return $this->hasMany(SparePartSale::class, 'work_order_part_return_id');
    }

    public function evidenceFiles(): HasMany
    {
        return $this->hasMany(WorkOrderPartReturnEvidence::class, 'work_order_part_return_id');
    }

    /** G-16: SELL_ELIGIBLE quantity not yet consumed by an active (non-rejected/cancelled) sale. */
    public function remainingEligibleQuantity(): float
    {
        $sold = (float) $this->sales()->whereIn('status', SparePartSale::ACTIVE_STATUSES)->sum('quantity');

        return (float) $this->accepted_quantity - $sold;
    }
}
