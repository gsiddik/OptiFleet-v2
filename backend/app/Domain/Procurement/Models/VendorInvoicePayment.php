<?php

namespace App\Domain\Procurement\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Full settlement of one vendor invoice (zero or one per invoice, enforced by a unique key). */
class VendorInvoicePayment extends Model
{
    use Auditable, BelongsToTenant, HasUuids;

    protected $fillable = [
        'tenant_id', 'vendor_invoice_reference_id', 'payment_date', 'amount',
        'proof_disk', 'proof_path', 'proof_original_name', 'proof_mime_type', 'proof_size', 'paid_by',
    ];

    protected $hidden = ['proof_disk', 'proof_path'];

    protected function casts(): array
    {
        return ['payment_date' => 'date', 'amount' => 'decimal:4'];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(VendorInvoiceReference::class, 'vendor_invoice_reference_id');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }
}
