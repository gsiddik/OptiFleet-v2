<?php

namespace App\Domain\Billing\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Contract\Models\Contract;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Subscription\Models\Subscription;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Billing extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'subscription_id',
        'tenant_id',
        'contract_id',
        'billing_period_start',
        'billing_period_end',
        'invoice_date',
        'due_date',
        'subtotal',
        'discount',
        'tax',
        'adjustment',
        'total',
        'status',
        'generated_at',
    ];

    protected function casts(): array
    {
        return [
            'billing_period_start' => 'date',
            'billing_period_end' => 'date',
            'invoice_date' => 'date',
            'due_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'adjustment' => 'decimal:2',
            'total' => 'decimal:2',
            'generated_at' => 'datetime',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(BillingItem::class);
    }
}
