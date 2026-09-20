<?php

namespace App\Domain\WorkOrder\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per named file slot on a WorkOrderExternalInvoice — never a
 * generic attachments list. `file_role` is one of the four fixed
 * documents the "Work Order Status External dan Workshop Invoice"
 * business rules require (Acknowledgement, Completed Work Order, Vendor
 * Invoice, Payment Proof); the unique (external_invoice_id, file_role)
 * constraint means uploading again for the same role replaces the row
 * rather than accumulating a history — the document does not describe a
 * correction/re-upload flow for these.
 */
class WorkOrderExternalInvoiceFile extends Model
{
    use BelongsToTenant, HasUuids;

    public const ROLES = ['ACKNOWLEDGEMENT', 'COMPLETED_WORK_ORDER', 'VENDOR_INVOICE', 'PAYMENT_PROOF'];

    protected $fillable = [
        'tenant_id', 'external_invoice_id', 'file_role', 'disk', 'path',
        'original_filename', 'mime_type', 'size', 'uploaded_by', 'uploaded_at',
    ];

    protected $casts = ['uploaded_at' => 'datetime'];

    public function externalInvoice(): BelongsTo
    {
        return $this->belongsTo(WorkOrderExternalInvoice::class, 'external_invoice_id');
    }
}
