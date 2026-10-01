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

class VendorInvoiceReference extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'partner_id', 'purchase_order_id', 'goods_receipt_id', 'vendor_invoice_number',
        'vendor_invoice_date', 'amount', 'terms_of_payment_days', 'due_date', 'attachment_path', 'attachment_disk',
        'attachment_original_name', 'attachment_mime_type', 'attachment_size', 'status', 'origin', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return ['vendor_invoice_date' => 'date', 'due_date' => 'date', 'amount' => 'decimal:4', 'terms_of_payment_days' => 'integer'];
    }

    protected $hidden = ['attachment_path', 'attachment_disk'];

    protected $appends = ['has_document'];

    public function getHasDocumentAttribute(): bool
    {
        return $this->attachment_path !== null;
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** Legacy single link (pre Goods-Receipt capture). New links live on goods_receipts. */
    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }

    /** The payment settling this invoice (paid once, whichever receipt row it is opened from). */
    public function payment(): HasOne
    {
        return $this->hasOne(VendorInvoicePayment::class);
    }

    /** Every Goods Receipt received against this invoice (one invoice may cover several). */
    public function goodsReceipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }
}
