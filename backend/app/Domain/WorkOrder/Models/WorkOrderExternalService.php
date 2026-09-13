<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Partner\Models\Partner;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkOrderExternalService extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    /** R1: memo lifecycle now extends past COMPLETED. See WorkshopInvoiceService for the BILLED/PAID transitions. */
    public const STATUSES = ['REQUESTED', 'COMPLETED', 'CANCELLED', 'BILLED', 'PAID'];

    protected $fillable = [
        'tenant_id', 'work_order_id', 'partner_id', 'memo_number', 'numbering_configuration_version_id',
        'description', 'diagnosis', 'requested_parts_services', 'photo_evidence', 'condition_notes', 'priority',
        'reference_number', 'cost', 'status', 'workshop_invoice_id',
        'requested_by', 'requested_at', 'completed_by', 'completed_at', 'cancelled_by', 'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'cost' => 'decimal:4',
            'requested_at' => 'datetime',
            'completed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function workshopInvoice(): BelongsTo
    {
        return $this->belongsTo(WorkshopInvoice::class);
    }
}
