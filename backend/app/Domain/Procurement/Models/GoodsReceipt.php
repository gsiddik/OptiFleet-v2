<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Partner\Models\Partner;
use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceipt extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = ['tenant_id', 'gr_number', 'numbering_configuration_version_id', 'purchase_order_id', 'warehouse_id', 'partner_id', 'vendor_invoice_reference_id', 'status', 'received_by', 'received_at', 'notes'];

    protected function casts(): array
    {
        return ['received_at' => 'datetime'];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function vendorInvoiceReference(): BelongsTo
    {
        return $this->belongsTo(VendorInvoiceReference::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceiptItem::class);
    }
}
