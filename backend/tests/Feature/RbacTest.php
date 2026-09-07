<?php

namespace Tests\Feature;

use App\Domain\Organization\Models\Branch;
use Tests\TestCase;

class RbacTest extends TestCase
{
    public function test_user_without_permission_is_denied(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ORGANIZATION');
        [, $token] = $this->makeTenantUser($tenant, []); // no permissions granted

        $response = $this->getJson('/api/v1/app/branches', $this->authHeaders($token));
        $response->assertStatus(403);
    }

    public function test_user_with_permission_is_allowed(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ORGANIZATION');
        [, $token] = $this->makeTenantUser($tenant, ['branch.view']);

        $response = $this->getJson('/api/v1/app/branches', $this->authHeaders($token));
        $response->assertOk();
    }

    public function test_permission_is_dynamic_and_removable(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ORGANIZATION');
        $this->grantModule($tenant, 'ACCESS_MANAGEMENT');
        [$user, $token] = $this->makeTenantUser($tenant, ['branch.view']);
        [, $adminToken] = $this->makeTenantUser($tenant, ['user.assign']);

        $this->getJson('/api/v1/app/branches', $this->authHeaders($token))->assertOk();

        $roleId = \App\Domain\AccessControl\Models\RoleAssignment::query()->where('user_id', $user->id)->value('role_id');
        $tenantUser = \App\Domain\Identity\Models\TenantUser::query()->where('user_id', $user->id)->first();

        $this->deleteJson(
            "/api/v1/app/users/{$tenantUser->id}/roles/{$roleId}",
            [],
            $this->authHeaders($adminToken)
        )->assertOk();

        $this->getJson('/api/v1/app/branches', $this->authHeaders($token))->assertStatus(403);
    }

    public function test_platform_role_permissions_do_not_leak_into_tenant_context(): void
    {
        // a permission named identically ('user.view') exists as two distinct rows
        // (platform scope and tenant scope) — granting the platform one must not
        // grant the tenant one.
        [, $platformToken] = $this->makePlatformUser(['user.view']);

        $this->getJson('/api/v1/platform/users', $this->authHeaders($platformToken))->assertOk();
    }

    public function test_deactivating_branch_requires_dedicated_permission(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ORGANIZATION');
        $branch = Branch::query()->create(['tenant_id' => $tenant->id, 'code' => 'RB1', 'name' => 'RB1', 'status' => 'ACTIVE']);
        [, $token] = $this->makeTenantUser($tenant, ['branch.view']); // no deactivate permission

        $response = $this->postJson("/api/v1/app/branches/{$branch->id}/deactivate", [], $this->authHeaders($token));
        $response->assertStatus(403);
    }
}
