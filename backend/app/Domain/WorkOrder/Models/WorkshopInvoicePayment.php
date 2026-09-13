<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * R1: payment evidence recorded against a WorkshopInvoice. One-to-one by
 * design (unique DB constraint on workshop_invoice_id) — the supplied
 * business decision does not describe partial payment, so this is not a
 * multi-instalment ledger.
 */
class WorkshopInvoicePayment extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'workshop_invoice_id', 'payment_date', 'paid_amount', 'payment_method',
        'reference_number', 'evidence_url', 'notes', 'uploaded_by', 'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'paid_amount' => 'decimal:4',
            'uploaded_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(WorkshopInvoice::class, 'workshop_invoice_id');
    }
}
