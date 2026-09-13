<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Partner\Models\Partner;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * R1 (Workshop Invoice and Settlement): a Workshop Invoice is an EXTERNALLY
 * issued document — the Workshop Partner issues it, an OptiFleet user
 * records it. This model never represents an OptiFleet-issued invoice;
 * every financial field here is a transcription of what the external
 * document states. `status` is this settlement record's own
 * correction/cancellation workflow state (RECORDED / CORRECTION_REQUESTED /
 * CANCELLATION_REQUESTED / CANCELLED) — entirely separate from the linked
 * Maintenance Memo's own COMPLETED -> BILLED -> PAID lifecycle.
 */
class WorkshopInvoice extends Model
{
    use Auditable, BelongsToTenant, HasUuids, SoftDeletes;

    public const STATUSES = ['RECORDED', 'CORRECTION_REQUESTED', 'CANCELLATION_REQUESTED', 'CANCELLED'];

    protected $fillable = [
        'tenant_id', 'work_order_external_service_id', 'work_order_id', 'partner_id',
        'external_invoice_number', 'external_invoice_number_normalized', 'invoice_date', 'due_date', 'currency',
        'subtotal', 'tax_total', 'discount_total', 'total_amount', 'line_items',
        'partner_reference', 'returned_memo_attachment_url', 'invoice_attachment_url', 'notes', 'reconciliation_note',
        'status', 'received_by', 'received_at',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'discount_total' => 'decimal:4',
            'total_amount' => 'decimal:4',
            'line_items' => 'array',
            'received_at' => 'datetime',
        ];
    }

    public function memo(): BelongsTo
    {
        return $this->belongsTo(WorkOrderExternalService::class, 'work_order_external_service_id');
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function payment(): HasOne
    {
        return $this->hasOne(WorkshopInvoicePayment::class);
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(WorkshopInvoiceCorrection::class)->orderByDesc('requested_at');
    }

    public function cancellations(): HasMany
    {
        return $this->hasMany(WorkshopInvoiceCancellation::class)->orderByDesc('requested_at');
    }
}
