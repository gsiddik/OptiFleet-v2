<?php

namespace App\Domain\Subscription\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Billing\Models\Billing;
use App\Domain\Contract\Models\Contract;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Invoice\Models\Invoice;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Subscription extends Model
{
    use Auditable, HasUuids;

    public const OPERATIONAL_STATUSES = ['ACTIVE', 'EXPIRING', 'PAST_DUE', 'GRACE_PERIOD'];

    protected $fillable = [
        'tenant_id',
        'contract_id',
        'start_date',
        'end_date',
        'next_billing_date',
        'grace_period_end',
        'status',
        'activated_at',
        'suspended_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'start_date' => 'date',
            'end_date' => 'date',
            'next_billing_date' => 'date',
            'grace_period_end' => 'date',
            'activated_at' => 'datetime',
            'suspended_at' => 'datetime',
            'cancelled_at' => 'datetime',
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

    public function billings(): HasMany
    {
        return $this->hasMany(Billing::class);
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function hasOperationalAccess(): bool
    {
        return in_array($this->status, self::OPERATIONAL_STATUSES, true);
    }

    public function isSuspended(): bool
    {
        return $this->status === 'SUSPENDED';
    }
}
