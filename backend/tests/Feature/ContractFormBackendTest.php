<?php

namespace Tests\Feature;

use Tests\TestCase;

class ContractFormBackendTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->makeBundle('CFB-BUNDLE', ['CORE']);
        $this->makePricing('BUNDLE', 'CFB-BUNDLE', '1000000');
        $this->makePricing('MODULE', 'CFB-MODULE', '500000');
    }

    private function draftPayload(array $overrides = [], array $items = []): array
    {
        return array_merge([
            'start_date' => now()->toDateString(),
            'end_date' => now()->addYear()->toDateString(),
            'billing_cycle' => 'MONTHLY',
            'payment_terms_days' => 14,
            'grace_period_days' => 7,
            'activation_requires_payment' => false,
            'items' => $items ?: [[
                'product_type' => 'BUNDLE',
                'product_reference' => 'CFB-BUNDLE',
                'description' => 'Bundle subscription',
                'quantity' => 1,
                'billing_frequency' => 'MONTHLY',
            ]],
        ], $overrides);
    }

    public function test_contract_created_from_tenant_route_locks_tenant_and_ignores_payload_tenant_id(): void
    {
        $tenant = $this->makeTenant();
        $otherTenant = $this->makeTenant();
        [, $token] = $this->makePlatformUser(['contract.create', 'tenant.view']);

        $response = $this->postJson(
            "/api/v1/platform/tenants/{$tenant->id}/contracts",
            $this->draftPayload(['tenant_id' => $otherTenant->id]), // manipulated payload
            $this->authHeaders($token)
        );

        $response->assertCreated();
        $this->assertSame($tenant->id, $response->json('data.tenant_id'));
        $this->assertSame('TENANT_MANAGEMENT', $response->json('data.source_context'));
    }

    public function test_contract_created_from_contract_management_requires_tenant_id_and_records_source(): void
    {
        [, $token] = $this->makePlatformUser(['contract.create']);

        $this->postJson('/api/v1/platform/contracts', $this->draftPayload(), $this->authHeaders($token))
            ->assertStatus(422)
            ->assertJsonValidationErrors('tenant_id');

        $tenant = $this->makeTenant();
        $response = $this->postJson('/api/v1/platform/contracts', $this->draftPayload(['tenant_id' => $tenant->id]), $this->authHeaders($token));
        $response->assertCreated();
        $this->assertSame('CONTRACT_MANAGEMENT', $response->json('data.source_context'));
    }

    public function test_unauthorized_user_cannot_create_contract_via_tenant_route(): void
    {
        $tenant = $this->makeTenant();
        [, $token] = $this->makePlatformUser(['contract.view']); // no contract.create

        $this->postJson("/api/v1/platform/tenants/{$tenant->id}/contracts", $this->draftPayload(), $this->authHeaders($token))
            ->assertStatus(403);
    }

    public function test_unit_price_below_active_price_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        [, $token] = $this->makePlatformUser(['contract.create']);

        $response = $this->postJson('/api/v1/platform/contracts', $this->draftPayload(['tenant_id' => $tenant->id], [[
            'product_type' => 'MODULE',
            'product_reference' => 'CFB-MODULE',
            'description' => 'Under-priced module',
            'quantity' => 1,
            'unit_price' => '100000', // active price is 500000
            'billing_frequency' => 'MONTHLY',
        ]]), $this->authHeaders($token));

        $response->assertStatus(422);
        $this->assertStringContainsString('cannot be lower than the active price', $response->json('message'));
    }

    public function test_unit_price_at_or_above_active_price_is_accepted(): void
    {
        $tenant = $this->makeTenant();
        [, $token] = $this->makePlatformUser(['contract.create']);

        $equal = $this->postJson('/api/v1/platform/contracts', $this->draftPayload(['tenant_id' => $tenant->id], [[
            'product_type' => 'MODULE',
            'product_reference' => 'CFB-MODULE',
            'description' => 'Equal price module',
            'quantity' => 1,
            'unit_price' => '500000',
            'billing_frequency' => 'MONTHLY',
        ]]), $this->authHeaders($token));
        $equal->assertCreated();

        $above = $this->postJson('/api/v1/platform/contracts', $this->draftPayload(['tenant_id' => $this->makeTenant()->id], [[
            'product_type' => 'MODULE',
            'product_reference' => 'CFB-MODULE',
            'description' => 'Above price module',
            'quantity' => 1,
            'unit_price' => '999999',
            'billing_frequency' => 'MONTHLY',
        ]]), $this->authHeaders($token));
        $above->assertCreated();
    }

    public function test_setup_fee_and_other_accept_manual_price_without_pricing_lookup(): void
    {
        $tenant = $this->makeTenant();
        [, $token] = $this->makePlatformUser(['contract.create']);

        $response = $this->postJson('/api/v1/platform/contracts', $this->draftPayload(['tenant_id' => $tenant->id], [
            [
                'product_type' => 'SETUP_FEE',
                'product_reference' => 'IGNORED-REFERENCE', // must be discarded server-side
                'description' => 'One-time setup fee',
                'quantity' => 1,
                'unit_price' => '250000',
                'billing_frequency' => 'MONTHLY',
            ],
            [
                'product_type' => 'OTHER',
                'description' => 'Ad-hoc charge',
                'quantity' => 1,
                'unit_price' => '0',
                'billing_frequency' => 'MONTHLY',
            ],
        ]), $this->authHeaders($token));

        $response->assertCreated();
        $items = $response->json('data.items');
        $this->assertNull($items[0]['product_reference']);
        $this->assertSame('250000.00', $items[0]['unit_price']);
        $this->assertSame('0.00', $items[1]['unit_price']);
    }

    public function test_setup_fee_without_unit_price_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        [, $token] = $this->makePlatformUser(['contract.create']);

        $response = $this->postJson('/api/v1/platform/contracts', $this->draftPayload(['tenant_id' => $tenant->id], [[
            'product_type' => 'SETUP_FEE',
            'description' => 'Missing price',
            'quantity' => 1,
            'billing_frequency' => 'MONTHLY',
        ]]), $this->authHeaders($token));

        $response->assertStatus(422);
        $this->assertStringContainsString('Unit price is required', $response->json('message'));
    }

    public function test_pricing_resolve_preview_endpoint_returns_active_price(): void
    {
        $tenant = $this->makeTenant();
        [, $token] = $this->makePlatformUser(['contract.create']);

        $response = $this->getJson(
            '/api/v1/platform/pricing/resolve?'.http_build_query([
                'priceable_type' => 'MODULE',
                'priceable_code' => 'CFB-MODULE',
                'billing_frequency' => 'MONTHLY',
                'tenant_id' => $tenant->id,
            ]),
            $this->authHeaders($token)
        );

        $response->assertOk();
        $this->assertSame('500000.00', $response->json('data.amount'));
    }

    public function test_zero_or_negative_quantity_is_rejected(): void
    {
        $tenant = $this->makeTenant();
        [, $token] = $this->makePlatformUser(['contract.create']);

        $response = $this->postJson('/api/v1/platform/contracts', $this->draftPayload(['tenant_id' => $tenant->id], [[
            'product_type' => 'MODULE',
            'product_reference' => 'CFB-MODULE',
            'description' => 'Zero qty',
            'quantity' => 0,
            'billing_frequency' => 'MONTHLY',
        ]]), $this->authHeaders($token));

        $response->assertStatus(422)->assertJsonValidationErrors('items.0.quantity');
    }

    public function test_invalid_enum_values_are_rejected(): void
    {
        $tenant = $this->makeTenant();
        [, $token] = $this->makePlatformUser(['contract.create']);

        $response = $this->postJson('/api/v1/platform/contracts', $this->draftPayload(['tenant_id' => $tenant->id], [[
            'product_type' => 'NOT_A_REAL_TYPE',
            'description' => 'Bad type',
            'quantity' => 1,
            'billing_frequency' => 'MONTHLY',
        ]]), $this->authHeaders($token));

        $response->assertStatus(422)->assertJsonValidationErrors('items.0.product_type');
    }
}
