<?php

namespace App\Domain\Invoice\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Billing\Models\Billing;
use App\Domain\Contract\Models\Contract;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Subscription\Models\Subscription;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'invoice_number',
        'tenant_id',
        'contract_id',
        'subscription_id',
        'billing_id',
        'invoice_date',
        'due_date',
        'currency',
        'subtotal',
        'discount',
        'tax',
        'adjustment',
        'total',
        'paid_amount',
        'outstanding_amount',
        'status',
        'issued_at',
        'paid_at',
        'voided_at',
        'void_reason',
        'replaced_by_invoice_id',
    ];

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'adjustment' => 'decimal:2',
            'total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'outstanding_amount' => 'decimal:2',
            'issued_at' => 'datetime',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function billing(): BelongsTo
    {
        return $this->belongsTo(Billing::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(\App\Domain\Payment\Models\Payment::class);
    }

    public function isFullyPaid(): bool
    {
        return bccomp((string) $this->outstanding_amount, '0.00', 2) <= 0;
    }
}
