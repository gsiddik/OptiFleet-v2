<?php

namespace App\Domain\Pricing\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PricingVersion extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'pricing_id',
        'version_number',
        'amount',
        'tiers',
        'effective_from',
        'effective_until',
        'status',
        'published_at',
        'published_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'tiers' => 'array',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function pricing(): BelongsTo
    {
        return $this->belongsTo(Pricing::class);
    }
}
