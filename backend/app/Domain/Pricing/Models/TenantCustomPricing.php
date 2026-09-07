<?php

namespace App\Domain\Pricing\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Identity\Models\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantCustomPricing extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'tenant_id',
        'pricing_id',
        'amount',
        'tiers',
        'effective_from',
        'effective_until',
        'reason',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'tiers' => 'array',
            'effective_from' => 'date',
            'effective_until' => 'date',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function pricing(): BelongsTo
    {
        return $this->belongsTo(Pricing::class);
    }
}
