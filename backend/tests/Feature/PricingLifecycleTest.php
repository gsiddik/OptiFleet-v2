<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Pricing\Models\Pricing;
use Tests\TestCase;

class PricingLifecycleTest extends TestCase
{
    public function test_deactivate_and_reactivate_require_permission_and_are_audited(): void
    {
        $pricing = $this->makePricing('MODULE', 'PLIFE1', '500000');

        [, $noPermToken] = $this->makePlatformUser(['pricing.view']);
        $this->postJson("/api/v1/platform/pricing/{$pricing->id}/deactivate", [], $this->authHeaders($noPermToken))
            ->assertStatus(403);

        [, $token] = $this->makePlatformUser(['pricing.view', 'pricing.deactivate', 'pricing.activate']);

        $this->postJson("/api/v1/platform/pricing/{$pricing->id}/deactivate", [], $this->authHeaders($token))
            ->assertOk()
            ->assertJsonPath('data.status', 'ARCHIVED');

        $this->assertTrue(
            AuditLog::query()->where('resource_type', 'Pricing')->where('resource_id', $pricing->id)->where('action', 'updated')->exists()
        );

        $this->postJson("/api/v1/platform/pricing/{$pricing->id}/reactivate", [], $this->authHeaders($token))
            ->assertOk()
            ->assertJsonPath('data.status', 'ACTIVE');
    }

    public function test_deactivated_pricing_is_excluded_from_resolution_but_reactivating_restores_it(): void
    {
        $pricing = $this->makePricing('MODULE', 'PLIFE2', '750000');
        [, $token] = $this->makePlatformUser(['pricing.deactivate', 'pricing.activate']);

        $resolution = app(\App\Domain\Pricing\Services\PricingResolutionService::class);
        $tenant = $this->makeTenant();
        $this->assertSame('750000.00', $resolution->resolveForTenant($tenant->id, 'MODULE', 'PLIFE2', 'MONTHLY')['amount']);

        $this->postJson("/api/v1/platform/pricing/{$pricing->id}/deactivate", [], $this->authHeaders($token))->assertOk();

        $this->expectException(\App\Domain\Pricing\Services\PricingException::class);
        $resolution->resolveForTenant($tenant->id, 'MODULE', 'PLIFE2', 'MONTHLY');
    }

    public function test_soft_deleted_pricing_is_excluded_from_default_list_and_resolution_but_keeps_historical_reference(): void
    {
        $pricing = $this->makePricing('BUNDLE', 'PLIFE3', '900000');
        $this->makeBundle('PLIFE3', ['CORE']);
        $tenant = $this->makeTenant();
        $contract = $this->makeContractDraft($tenant, 'PLIFE3');
        $historicalPricingVersionId = $contract->items->first()->pricing_version_id;

        [, $token] = $this->makePlatformUser(['pricing.view', 'pricing.delete']);
        $this->deleteJson("/api/v1/platform/pricing/{$pricing->id}", [], $this->authHeaders($token))->assertOk();

        $this->assertSoftDeleted('pricings', ['id' => $pricing->id]);

        $listResponse = $this->getJson('/api/v1/platform/pricing', $this->authHeaders($token));
        $this->assertFalse(collect($listResponse->json('data'))->contains('id', $pricing->id));

        $this->expectException(\App\Domain\Pricing\Services\PricingException::class);
        app(\App\Domain\Pricing\Services\PricingResolutionService::class)->resolveForTenant($tenant->id, 'BUNDLE', 'PLIFE3', 'MONTHLY');

        // Meanwhile the already-created contract item's frozen snapshot is untouched.
        $contract->refresh();
        $this->assertSame($historicalPricingVersionId, $contract->items->first()->pricing_version_id);
        $this->assertNotNull(\App\Domain\Pricing\Models\PricingVersion::query()->find($historicalPricingVersionId));
    }

    public function test_a_new_price_version_does_not_change_an_existing_contract_items_frozen_price(): void
    {
        $pricing = $this->makePricing('BUNDLE', 'PLIFE4', '1000000');
        $this->makeBundle('PLIFE4', ['CORE']);
        $tenant = $this->makeTenant();
        $contract = $this->makeContractDraft($tenant, 'PLIFE4');
        $this->assertSame('1000000.00', (string) $contract->items->first()->unit_price);

        [, $token] = $this->makePlatformUser(['pricing.publish']);
        $this->postJson("/api/v1/platform/pricing/{$pricing->id}/versions", [
            'amount' => '5000000',
            'effective_from' => now()->toDateString(),
        ], $this->authHeaders($token))->assertCreated();

        $contract->refresh();
        $this->assertSame('1000000.00', (string) $contract->items->first()->unit_price);
    }
}
