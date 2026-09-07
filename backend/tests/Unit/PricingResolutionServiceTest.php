<?php

namespace Tests\Unit;

use App\Domain\Pricing\Models\TenantCustomPricing;
use App\Domain\Pricing\Services\PricingResolutionService;
use Tests\TestCase;

class PricingResolutionServiceTest extends TestCase
{
    public function test_resolves_active_standard_pricing_version(): void
    {
        $this->makePricing('MODULE', 'VEHICLE', '1000000');
        $tenant = $this->makeTenant();

        $resolved = app(PricingResolutionService::class)->resolveForTenant($tenant->id, 'MODULE', 'VEHICLE', 'MONTHLY');

        $this->assertSame('STANDARD', $resolved['source']);
        $this->assertSame('1000000.00', $resolved['amount']);
    }

    public function test_tenant_custom_pricing_takes_priority_over_standard(): void
    {
        $pricing = $this->makePricing('MODULE', 'VEHICLE', '1000000');
        $tenant = $this->makeTenant();

        TenantCustomPricing::query()->create([
            'tenant_id' => $tenant->id,
            'pricing_id' => $pricing->id,
            'amount' => '850000',
            'effective_from' => now()->subMonth()->toDateString(),
            'status' => 'ACTIVE',
        ]);

        $resolved = app(PricingResolutionService::class)->resolveForTenant($tenant->id, 'MODULE', 'VEHICLE', 'MONTHLY');

        $this->assertSame('TENANT_CUSTOM', $resolved['source']);
        $this->assertSame('850000.00', $resolved['amount']);
    }

    public function test_inactive_custom_pricing_is_ignored(): void
    {
        $pricing = $this->makePricing('MODULE', 'VEHICLE', '1000000');
        $tenant = $this->makeTenant();

        TenantCustomPricing::query()->create([
            'tenant_id' => $tenant->id,
            'pricing_id' => $pricing->id,
            'amount' => '850000',
            'effective_from' => now()->subMonth()->toDateString(),
            'status' => 'INACTIVE',
        ]);

        $resolved = app(PricingResolutionService::class)->resolveForTenant($tenant->id, 'MODULE', 'VEHICLE', 'MONTHLY');

        $this->assertSame('STANDARD', $resolved['source']);
    }

    public function test_publishing_new_version_closes_out_the_previous_one(): void
    {
        $pricing = $this->makePricing('MODULE', 'VEHICLE', '1000000');

        app(PricingResolutionService::class)->publishVersion($pricing, [
            'amount' => '1200000',
            'effective_from' => now()->toDateString(),
        ]);

        $versions = $pricing->fresh()->versions()->orderBy('version_number')->get();
        $this->assertCount(2, $versions);
        $this->assertNotNull($versions->first()->effective_until);
        $this->assertSame('1200000.00', (string) $versions->last()->amount);
    }
}
