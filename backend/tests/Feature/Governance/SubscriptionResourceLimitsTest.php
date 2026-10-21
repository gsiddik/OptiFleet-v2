<?php

namespace Tests\Feature\Governance;

use App\Domain\Entitlement\Models\TenantCapacityLimit;
use Tests\Concerns\InteractsWithCentralGovernance;
use Tests\TestCase;

class SubscriptionResourceLimitsTest extends TestCase
{
    use InteractsWithCentralGovernance;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCentralGovernance();
    }

    public function test_central_subscription_displays_exact_resource_keys_and_missing_keys_are_unlimited(): void
    {
        $tenant = $this->makeCentralTenant();
        $this->nexus->tenants[$tenant->optinexus_tenant_id]['resource_limits'] = [
            'vehicle' => 12, 'user' => 0, 'warehouse' => 'unlimited', 'branches' => 99, 'unrelated_limit' => -99,
        ];
        // This standalone limit must not leak into the centrally governed display.
        TenantCapacityLimit::create(['tenant_id' => $tenant->id, 'resource_type' => 'branch', 'max_count' => 1]);
        [, $token] = $this->makeCentralUser($tenant, ['account.subscription.view']);

        $response = $this->getJson('/api/v1/app/account/usage-limits', $this->authHeaders($token))->assertOk();
        $rows = collect($response->json('data'))->keyBy('resource_type');
        $this->assertSame(12, $rows['vehicle']['max_count']);
        $this->assertSame(0, $rows['user']['max_count']);
        $this->assertNull($rows['warehouse']['max_count']);
        $this->assertNull($rows['branch']['max_count']);
        $this->assertNull($rows['workshop']['max_count']);
    }

    public function test_local_subscription_keeps_its_local_capacity_limit(): void
    {
        $tenant = $this->makeTenant();
        TenantCapacityLimit::create(['tenant_id' => $tenant->id, 'resource_type' => 'vehicle', 'max_count' => 4]);
        [, $token] = $this->makeTenantUser($tenant, ['account.subscription.view']);

        $this->getJson('/api/v1/app/account/usage-limits', $this->authHeaders($token))
            ->assertOk()->assertJsonPath('data.0.max_count', 4);
    }

    public function test_invalid_central_limit_does_not_become_unlimited(): void
    {
        $tenant = $this->makeCentralTenant();
        $this->nexus->tenants[$tenant->optinexus_tenant_id]['resource_limits'] = ['vehicle' => -1];
        [, $token] = $this->makeCentralUser($tenant, ['account.subscription.view']);

        $this->getJson('/api/v1/app/account/usage-limits', $this->authHeaders($token))->assertStatus(503);
    }

    public function test_two_customers_keep_their_own_nexus_limits(): void
    {
        $first = $this->makeCentralTenant();
        $second = $this->makeCentralTenant();
        $this->nexus->tenants[$first->optinexus_tenant_id]['resource_limits'] = ['vehicle' => 2];
        $this->nexus->tenants[$second->optinexus_tenant_id]['resource_limits'] = ['vehicle' => 20];
        [, $firstToken] = $this->makeCentralUser($first, ['account.subscription.view']);
        [, $secondToken] = $this->makeCentralUser($second, ['account.subscription.view']);

        $this->getJson('/api/v1/app/account/usage-limits', $this->authHeaders($firstToken))
            ->assertOk()->assertJsonPath('data.0.max_count', 2);
        $this->getJson('/api/v1/app/account/usage-limits', $this->authHeaders($secondToken))
            ->assertOk()->assertJsonPath('data.0.max_count', 20);
    }
}
