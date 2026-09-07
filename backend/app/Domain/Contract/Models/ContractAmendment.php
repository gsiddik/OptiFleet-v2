<?php

namespace App\Domain\Contract\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContractAmendment extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'contract_id',
        'amendment_number',
        'status',
        'reason',
        'before_snapshot',
        'after_snapshot',
        'effective_date',
        'proration_amount',
        'created_by',
        'approved_by',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'before_snapshot' => 'array',
            'after_snapshot' => 'array',
            'effective_date' => 'date',
            'proration_amount' => 'decimal:2',
            'approved_at' => 'datetime',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(ContractAmendmentItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
