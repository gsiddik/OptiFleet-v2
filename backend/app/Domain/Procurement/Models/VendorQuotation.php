<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Partner\Models\Partner;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class VendorQuotation extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'rfq_id', 'partner_id', 'quotation_number', 'validity_date', 'lead_time_days',
        'payment_terms', 'freight_cost', 'subtotal', 'tax_total', 'total', 'status', 'submitted_at', 'notes',
        'attachment_disk', 'attachment_path', 'attachment_original_filename', 'attachment_mime_type',
        'attachment_size', 'attachment_uploaded_by', 'attachment_uploaded_at',
    ];

    /** The storage location is never serialised; the file is served by the authorized endpoint. */
    protected $hidden = ['attachment_disk', 'attachment_path'];

    protected $appends = ['has_attachment'];

    /** The only status from which a Purchase Order may be created (the vendor chosen on the RFQ). */
    public const PURCHASE_ORDER_SOURCE_STATUS = 'SELECTED';

    protected function casts(): array
    {
        return [
            'validity_date' => 'date',
            'freight_cost' => 'decimal:4',
            'subtotal' => 'decimal:4',
            'tax_total' => 'decimal:4',
            'total' => 'decimal:4',
            'submitted_at' => 'datetime',
            'attachment_uploaded_at' => 'datetime',
        ];
    }

    protected function getHasAttachmentAttribute(): bool
    {
        return $this->attachment_path !== null;
    }

    public function rfq(): BelongsTo
    {
        return $this->belongsTo(Rfq::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(VendorQuotationItem::class);
    }

    /** The PO created from this quotation (purchase_orders.vendor_quotation_id, unique). */
    public function purchaseOrder(): HasOne
    {
        return $this->hasOne(PurchaseOrder::class, 'vendor_quotation_id');
    }

    /**
     * Whether "Create PO" is allowed: the quotation is the selected one and no Purchase Order has
     * been created from it yet (SUBMITTED / REJECTED quotations, and the SUBMITTED quotations of an
     * RFQ closed without a selection, are ineligible). Serialised as `can_create_purchase_order`
     * (eager-load `purchaseOrder` first in lists); PurchaseOrderService::createFromQuotation()
     * enforces the same rule under a row lock.
     */
    public function canCreatePurchaseOrder(): bool
    {
        return $this->status === self::PURCHASE_ORDER_SOURCE_STATUS && $this->purchaseOrder === null;
    }

    protected function getCanCreatePurchaseOrderAttribute(): bool
    {
        return $this->canCreatePurchaseOrder();
    }
}
