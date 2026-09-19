<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A lightweight, non-financial "Workshop Invoice source reference" — NOT
 * the WorkshopInvoice model (that represents an actually-received external
 * invoice document for the separate towing/3rd-party-service subsystem).
 * This row's only job is to mark "this Work Order has been finalized as
 * External at least once, and is queued for the future external-invoice
 * process." It is created once and reused across every revise/re-finalize
 * cycle (never duplicated); its NEW_EXTERNAL_WO/active eligibility is
 * derived from the parent Work Order's live status at read time, not
 * stored on this row, so the two can never drift out of sync.
 */
class WorkOrderExternalReference extends Model
{
    use BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'branch_id', 'work_order_id'];

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public const DISPLAY_STATUS = 'NEW_EXTERNAL_WO';

    /** Active/ready-for-processing only while the parent Work Order is currently EXTERNAL. */
    public function isActive(): bool
    {
        return $this->workOrder->status === 'EXTERNAL';
    }
}
