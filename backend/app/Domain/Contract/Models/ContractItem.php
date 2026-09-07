<?php

namespace App\Domain\Contract\Models;

use App\Domain\ProductCatalog\Models\BundleVersion;
use App\Domain\Pricing\Models\PricingVersion;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ContractItem extends Model
{
    use HasUuids;

    protected $fillable = [
        'contract_id',
        'product_type',
        'product_reference',
        'bundle_version_id',
        'pricing_version_id',
        'description',
        'quantity',
        'unit_price',
        'discount',
        'tax',
        'final_amount',
        'billing_frequency',
        'valid_from',
        'valid_until',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2',
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'final_amount' => 'decimal:2',
            'valid_from' => 'date',
            'valid_until' => 'date',
        ];
    }

    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    public function bundleVersion(): BelongsTo
    {
        return $this->belongsTo(BundleVersion::class);
    }

    public function pricingVersion(): BelongsTo
    {
        return $this->belongsTo(PricingVersion::class);
    }

    public function isActiveOn(\DateTimeInterface|string $date): bool
    {
        $date = $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : $date;

        return $this->valid_from->format('Y-m-d') <= $date
            && (! $this->valid_until || $this->valid_until->format('Y-m-d') >= $date);
    }
}
