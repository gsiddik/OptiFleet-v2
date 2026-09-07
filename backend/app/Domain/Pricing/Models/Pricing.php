<?php

namespace App\Domain\Pricing\Models;

use App\Domain\Audit\Concerns\Auditable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pricing extends Model
{
    use Auditable, HasUuids;

    protected $fillable = [
        'priceable_type',
        'priceable_code',
        'pricing_method',
        'billing_frequency',
        'currency',
        'status',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(PricingVersion::class);
    }

    public function customPricings(): HasMany
    {
        return $this->hasMany(TenantCustomPricing::class);
    }
}
