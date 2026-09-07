<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Partner\Models\Partner;
use App\Domain\Shared\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorInvoiceReference extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'partner_id', 'purchase_order_id', 'goods_receipt_id', 'vendor_invoice_number',
        'vendor_invoice_date', 'amount', 'attachment_path', 'attachment_disk', 'status', 'notes',
    ];

    protected function casts(): array
    {
        return ['vendor_invoice_date' => 'date', 'amount' => 'decimal:4'];
    }

    protected $hidden = ['attachment_path', 'attachment_disk'];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function goodsReceipt(): BelongsTo
    {
        return $this->belongsTo(GoodsReceipt::class);
    }
}
