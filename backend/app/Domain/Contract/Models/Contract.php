<?php

namespace App\Domain\Contract\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Subscription\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Contract extends Model
{
    use Auditable, HasUuids;

    public const ACTIVATABLE_STATUSES = ['APPROVED'];

    protected $fillable = [
        'contract_number',
        'tenant_id',
        'start_date',
        'end_date',
        'billing_cycle',
        'payment_terms_days',
        'grace_period_days',
        'currency',
        'subtotal',
        'discount',
        'tax',
        'total',
        'status',
        'activation_requires_payment',
        'renewed_from_contract_id',
        'notes',
        'created_by',
        'source_context',
        'approved_at',
        'activated_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total' => 'decimal:2',
            'activation_requires_payment' => 'boolean',
            'approved_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContractItem::class);
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ContractApproval::class);
    }

    public function amendments(): HasMany
    {
        return $this->hasMany(ContractAmendment::class);
    }

    public function subscription(): HasOne
    {
        return $this->hasOne(Subscription::class);
    }

    public function renewedFrom(): BelongsTo
    {
        return $this->belongsTo(Contract::class, 'renewed_from_contract_id');
    }

    public function isEditable(): bool
    {
        return $this->status === 'DRAFT';
    }
}
