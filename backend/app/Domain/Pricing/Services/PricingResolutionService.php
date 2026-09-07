<?php

namespace App\Domain\Pricing\Services;

use App\Domain\Pricing\Models\Pricing;
use App\Domain\Pricing\Models\PricingVersion;
use App\Domain\Pricing\Models\TenantCustomPricing;
use Illuminate\Support\Carbon;

/**
 * Resolves the price to charge a tenant for a priceable product at a given
 * date. Priority (Section 8): tenant custom price first, otherwise the
 * active standard pricing version. This is used only at the moment a
 * contract item (or amendment item) is created — the result is then frozen
 * into that item and never re-resolved from here again.
 */
class PricingResolutionService
{
    public function resolveStandard(string $priceableType, string $priceableCode, string $billingFrequency, ?string $date = null): ?PricingVersion
    {
        $date ??= now()->toDateString();

        $pricing = Pricing::query()
            ->where('priceable_type', $priceableType)
            ->where('priceable_code', $priceableCode)
            ->where('billing_frequency', $billingFrequency)
            ->where('status', 'ACTIVE')
            ->first();

        if (! $pricing) {
            return null;
        }

        return $this->activeVersionAt($pricing, $date);
    }

    public function resolveForTenant(string $tenantId, string $priceableType, string $priceableCode, string $billingFrequency, ?string $date = null): array
    {
        $date ??= now()->toDateString();

        $pricing = Pricing::query()
            ->where('priceable_type', $priceableType)
            ->where('priceable_code', $priceableCode)
            ->where('billing_frequency', $billingFrequency)
            ->where('status', 'ACTIVE')
            ->first();

        if (! $pricing) {
            throw new PricingException("No active pricing found for {$priceableType} {$priceableCode} ({$billingFrequency}).");
        }

        $custom = TenantCustomPricing::query()
            ->where('tenant_id', $tenantId)
            ->where('pricing_id', $pricing->id)
            ->where('status', 'ACTIVE')
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date);
            })
            ->latest('effective_from')
            ->first();

        if ($custom) {
            return [
                'amount' => (string) $custom->amount,
                'tiers' => $custom->tiers,
                'pricing_id' => $pricing->id,
                'pricing_version_id' => null,
                'source' => 'TENANT_CUSTOM',
            ];
        }

        $version = $this->activeVersionAt($pricing, $date);
        if (! $version) {
            throw new PricingException("No active pricing version found for {$priceableType} {$priceableCode} on {$date}.");
        }

        return [
            'amount' => (string) $version->amount,
            'tiers' => $version->tiers,
            'pricing_id' => $pricing->id,
            'pricing_version_id' => $version->id,
            'source' => 'STANDARD',
        ];
    }

    private function activeVersionAt(Pricing $pricing, string $date): ?PricingVersion
    {
        return PricingVersion::query()
            ->where('pricing_id', $pricing->id)
            ->where('status', 'ACTIVE')
            ->where('effective_from', '<=', $date)
            ->where(function ($q) use ($date) {
                $q->whereNull('effective_until')->orWhere('effective_until', '>=', $date);
            })
            ->orderByDesc('effective_from')
            ->first();
    }

    public function publishVersion(Pricing $pricing, array $attributes, ?string $publishedByUserId = null): PricingVersion
    {
        $nextVersion = ($pricing->versions()->max('version_number') ?? 0) + 1;

        // Close out the currently active version at the day before the new
        // one starts, so periods never overlap.
        $newFrom = Carbon::parse($attributes['effective_from']);
        PricingVersion::query()
            ->where('pricing_id', $pricing->id)
            ->where('status', 'ACTIVE')
            ->whereNull('effective_until')
            ->update(['effective_until' => $newFrom->copy()->subDay()->toDateString()]);

        $version = PricingVersion::query()->create(array_merge($attributes, [
            'pricing_id' => $pricing->id,
            'version_number' => $nextVersion,
            'status' => 'ACTIVE',
            'published_at' => now(),
            'published_by' => $publishedByUserId,
        ]));

        if ($pricing->status === 'DRAFT') {
            $pricing->update(['status' => 'ACTIVE']);
        }

        return $version;
    }
}
