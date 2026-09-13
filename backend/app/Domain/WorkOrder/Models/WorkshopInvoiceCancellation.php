<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** R1: one requested cancellation of a WorkshopInvoice, maker-checker gated (see WorkshopInvoiceService::decideCancellation). */
class WorkshopInvoiceCancellation extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    public const STATUSES = ['PENDING', 'APPROVED', 'REJECTED'];

    protected $fillable = [
        'tenant_id', 'workshop_invoice_id', 'reason',
        'status', 'requested_by', 'requested_at', 'decided_by', 'decided_at', 'decision_note',
    ];

    protected function casts(): array
    {
        return [
            'requested_at' => 'datetime',
            'decided_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(WorkshopInvoice::class, 'workshop_invoice_id');
    }
}
