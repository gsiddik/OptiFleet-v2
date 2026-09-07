<?php

namespace Tests\Feature;

use Tests\TestCase;

class ModuleEntitlementTest extends TestCase
{
    public function test_tenant_without_module_entitlement_is_blocked(): void
    {
        $tenant = $this->makeTenant(); // no ORGANIZATION entitlement granted
        [, $token] = $this->makeTenantUser($tenant, ['branch.view']);

        $response = $this->getJson('/api/v1/app/branches', $this->authHeaders($token));
        $response->assertStatus(403);
    }

    public function test_tenant_with_module_entitlement_is_allowed(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ORGANIZATION');
        [, $token] = $this->makeTenantUser($tenant, ['branch.view']);

        $response = $this->getJson('/api/v1/app/branches', $this->authHeaders($token));
        $response->assertOk();
    }

    public function test_platform_can_revoke_entitlement_and_it_takes_effect(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ORGANIZATION');
        [, $tenantToken] = $this->makeTenantUser($tenant, ['branch.view']);
        [, $platformToken] = $this->makePlatformUser(['entitlement.manage', 'entitlement.view']);

        $this->getJson('/api/v1/app/branches', $this->authHeaders($tenantToken))->assertOk();

        $module = \App\Domain\ProductCatalog\Models\Module::query()->where('code', 'ORGANIZATION')->first();
        $this->postJson("/api/v1/platform/tenants/{$tenant->id}/entitlements", [
            'module_id' => $module->id,
            'active' => false,
        ], $this->authHeaders($platformToken))->assertOk();

        $this->getJson('/api/v1/app/branches', $this->authHeaders($tenantToken))->assertStatus(403);
    }

    public function test_granting_module_requires_its_dependencies_to_be_active(): void
    {
        $tenant = $this->makeTenant();
        [, $platformToken] = $this->makePlatformUser(['entitlement.manage']);

        // WORK_ORDER depends on VEHICLE, MAINTENANCE, WORKSHOP — none active yet.
        $workOrder = \App\Domain\ProductCatalog\Models\Module::query()->where('code', 'WORK_ORDER')->first();

        $response = $this->postJson("/api/v1/platform/tenants/{$tenant->id}/entitlements", [
            'module_id' => $workOrder->id,
            'active' => true,
        ], $this->authHeaders($platformToken));

        $response->assertStatus(422);
    }

    public function test_revoking_a_module_blocked_when_dependent_module_still_active(): void
    {
        $tenant = $this->makeTenant();
        [, $platformToken] = $this->makePlatformUser(['entitlement.manage']);

        foreach (['VEHICLE', 'INSPECTION'] as $code) {
            $this->grantModule($tenant, $code);
        }

        $vehicle = \App\Domain\ProductCatalog\Models\Module::query()->where('code', 'VEHICLE')->first();

        $response = $this->postJson("/api/v1/platform/tenants/{$tenant->id}/entitlements", [
            'module_id' => $vehicle->id,
            'active' => false,
        ], $this->authHeaders($platformToken));

        $response->assertStatus(422);
    }
}
