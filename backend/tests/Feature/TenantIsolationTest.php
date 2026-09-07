<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Branch;
use Tests\TestCase;

class TenantIsolationTest extends TestCase
{
    public function test_tenant_cannot_list_another_tenants_branches(): void
    {
        $tenantA = $this->makeTenant(['code' => 'ISO-A']);
        $tenantB = $this->makeTenant(['code' => 'ISO-B']);
        $this->grantModule($tenantA, 'ORGANIZATION');
        $this->grantModule($tenantB, 'ORGANIZATION');

        Branch::query()->create(['tenant_id' => $tenantA->id, 'code' => 'BR-A', 'name' => 'Branch A', 'status' => 'ACTIVE']);
        Branch::query()->create(['tenant_id' => $tenantB->id, 'code' => 'BR-B', 'name' => 'Branch B', 'status' => 'ACTIVE']);

        [, $tokenB] = $this->makeTenantUser($tenantB, ['branch.view']);

        $response = $this->getJson('/api/v1/app/branches', $this->authHeaders($tokenB));
        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code');
        $this->assertTrue($codes->contains('BR-B'));
        $this->assertFalse($codes->contains('BR-A'));
    }

    public function test_cross_tenant_get_is_denied(): void
    {
        $tenantA = $this->makeTenant(['code' => 'ISO-A2']);
        $tenantB = $this->makeTenant(['code' => 'ISO-B2']);
        $this->grantModule($tenantA, 'ORGANIZATION');
        $this->grantModule($tenantB, 'ORGANIZATION');

        $branchA = Branch::query()->create(['tenant_id' => $tenantA->id, 'code' => 'BR-A2', 'name' => 'Branch A2', 'status' => 'ACTIVE']);
        [, $tokenB] = $this->makeTenantUser($tenantB, ['branch.view']);

        $response = $this->getJson("/api/v1/app/branches/{$branchA->id}", $this->authHeaders($tokenB));
        $response->assertStatus(404);
    }

    public function test_cross_tenant_update_is_denied(): void
    {
        $tenantA = $this->makeTenant(['code' => 'ISO-A3']);
        $tenantB = $this->makeTenant(['code' => 'ISO-B3']);
        $this->grantModule($tenantA, 'ORGANIZATION');
        $this->grantModule($tenantB, 'ORGANIZATION');

        $branchA = Branch::query()->create(['tenant_id' => $tenantA->id, 'code' => 'BR-A3', 'name' => 'Branch A3', 'status' => 'ACTIVE']);
        [, $tokenB] = $this->makeTenantUser($tenantB, ['branch.update']);

        $response = $this->putJson("/api/v1/app/branches/{$branchA->id}", ['name' => 'Hacked'], $this->authHeaders($tokenB));
        $response->assertStatus(404);

        $this->assertSame('Branch A3', $branchA->fresh()->name);
    }

    public function test_cross_tenant_deactivate_is_denied(): void
    {
        $tenantA = $this->makeTenant(['code' => 'ISO-A4']);
        $tenantB = $this->makeTenant(['code' => 'ISO-B4']);
        $this->grantModule($tenantA, 'ORGANIZATION');
        $this->grantModule($tenantB, 'ORGANIZATION');

        $branchA = Branch::query()->create(['tenant_id' => $tenantA->id, 'code' => 'BR-A4', 'name' => 'Branch A4', 'status' => 'ACTIVE']);
        [, $tokenB] = $this->makeTenantUser($tenantB, ['branch.deactivate']);

        $response = $this->postJson("/api/v1/app/branches/{$branchA->id}/deactivate", [], $this->authHeaders($tokenB));
        $response->assertStatus(404);

        $this->assertSame('ACTIVE', $branchA->fresh()->status);
    }

    public function test_tenant_cannot_create_workshop_referencing_another_tenants_branch(): void
    {
        $tenantA = $this->makeTenant(['code' => 'ISO-A5']);
        $tenantB = $this->makeTenant(['code' => 'ISO-B5']);
        $this->grantModule($tenantA, 'ORGANIZATION');
        $this->grantModule($tenantB, 'ORGANIZATION');

        $branchA = Branch::query()->create(['tenant_id' => $tenantA->id, 'code' => 'BR-A5', 'name' => 'Branch A5', 'status' => 'ACTIVE']);
        [, $tokenB] = $this->makeTenantUser($tenantB, ['workshop.create']);

        $response = $this->postJson('/api/v1/app/workshops', [
            'code' => 'WS-X',
            'name' => 'Cross Tenant Workshop',
            'branch_id' => $branchA->id,
        ], $this->authHeaders($tokenB));

        $response->assertStatus(422);
    }

    public function test_platform_admin_can_view_any_tenant(): void
    {
        $tenant = $this->makeTenant(['code' => 'ISO-P1']);
        [, $token] = $this->makePlatformUser(['tenant.view']);

        $this->getJson("/api/v1/platform/tenants/{$tenant->id}", $this->authHeaders($token))->assertOk();
    }
}
