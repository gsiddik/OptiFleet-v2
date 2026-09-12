<?php

namespace Tests\Feature;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase G — G-16: RoleController's tenant scoping (server-derived tenant_id
 * on create, explicit authorizeTenantRole() check on update/assignPermissions)
 * was already correct by inspection but had no test ever exercising
 * cross-tenant access. This is pure test-coverage addition — no behavior
 * change, unless a real bug surfaces.
 */
class RoleTenantIsolationTest extends TestCase
{
    public function test_role_created_by_a_tenant_is_stamped_with_that_tenants_id_not_client_input(): void
    {
        $tenant = $this->makeTenant(['code' => 'ROL-'.Str::random(4)]);
        $this->grantModule($tenant, 'ACCESS_MANAGEMENT');
        $otherTenant = $this->makeTenant(['code' => 'ROLX-'.Str::random(4)]);
        $this->grantModule($otherTenant, 'ACCESS_MANAGEMENT');
        [, $token] = $this->makeTenantUser($tenant, ['role.view', 'role.create']);

        $response = $this->postJson('/api/v1/app/roles', [
            'name' => 'Custom Role', 'tenant_id' => $otherTenant->id,
        ], $this->authHeaders($token))->assertStatus(201);

        $role = Role::query()->findOrFail($response->json('data.id'));
        $this->assertSame($tenant->id, $role->tenant_id);
        $this->assertNotSame($otherTenant->id, $role->tenant_id);
    }

    public function test_tenant_cannot_update_a_role_belonging_to_another_tenant(): void
    {
        $tenantA = $this->makeTenant(['code' => 'ROLA-'.Str::random(4)]);
        $this->grantModule($tenantA, 'ACCESS_MANAGEMENT');
        $tenantB = $this->makeTenant(['code' => 'ROLB-'.Str::random(4)]);
        $this->grantModule($tenantB, 'ACCESS_MANAGEMENT');
        [, $tokenA] = $this->makeTenantUser($tenantA, ['role.view', 'role.create']);
        [, $tokenB] = $this->makeTenantUser($tenantB, ['role.update']);

        $roleA = $this->postJson('/api/v1/app/roles', ['name' => 'Tenant A Role'], $this->authHeaders($tokenA))->json('data.id');

        $this->putJson("/api/v1/app/roles/{$roleA}", ['name' => 'Hacked'], $this->authHeaders($tokenB))->assertStatus(404);
        $this->assertSame('Tenant A Role', Role::query()->findOrFail($roleA)->name);
    }

    public function test_tenant_cannot_assign_permissions_to_a_role_belonging_to_another_tenant(): void
    {
        $tenantA = $this->makeTenant(['code' => 'ROLC-'.Str::random(4)]);
        $this->grantModule($tenantA, 'ACCESS_MANAGEMENT');
        $tenantB = $this->makeTenant(['code' => 'ROLD-'.Str::random(4)]);
        $this->grantModule($tenantB, 'ACCESS_MANAGEMENT');
        [, $tokenA] = $this->makeTenantUser($tenantA, ['role.view', 'role.create']);
        [, $tokenB] = $this->makeTenantUser($tenantB, ['role.assign_permission']);

        $roleA = $this->postJson('/api/v1/app/roles', ['name' => 'Tenant A Role'], $this->authHeaders($tokenA))->json('data.id');
        $permissionId = Permission::query()->where('scope', 'tenant')->where('name', 'role.view')->value('id');

        $this->postJson("/api/v1/app/roles/{$roleA}/permissions", ['permission_ids' => [$permissionId]], $this->authHeaders($tokenB))->assertStatus(404);
    }

    public function test_role_listing_never_includes_another_tenants_roles(): void
    {
        $tenantA = $this->makeTenant(['code' => 'ROLE-'.Str::random(4)]);
        $this->grantModule($tenantA, 'ACCESS_MANAGEMENT');
        $tenantB = $this->makeTenant(['code' => 'ROLF-'.Str::random(4)]);
        $this->grantModule($tenantB, 'ACCESS_MANAGEMENT');
        [, $tokenA] = $this->makeTenantUser($tenantA, ['role.view', 'role.create']);
        [, $tokenB] = $this->makeTenantUser($tenantB, ['role.view', 'role.create']);

        $this->postJson('/api/v1/app/roles', ['name' => 'Tenant A Only Role'], $this->authHeaders($tokenA))->assertStatus(201);
        $this->postJson('/api/v1/app/roles', ['name' => 'Tenant B Only Role'], $this->authHeaders($tokenB))->assertStatus(201);

        $listA = $this->getJson('/api/v1/app/roles', $this->authHeaders($tokenA))->assertOk();
        $names = collect($listA->json('data'))->pluck('name');
        $this->assertTrue($names->contains('Tenant A Only Role'));
        $this->assertFalse($names->contains('Tenant B Only Role'));
    }

    public function test_assigning_only_tenant_scoped_permissions_ignores_platform_scoped_ids(): void
    {
        $tenant = $this->makeTenant(['code' => 'ROLG-'.Str::random(4)]);
        $this->grantModule($tenant, 'ACCESS_MANAGEMENT');
        [, $token] = $this->makeTenantUser($tenant, ['role.view', 'role.create', 'role.assign_permission']);
        $roleId = $this->postJson('/api/v1/app/roles', ['name' => 'Scoped Role'], $this->authHeaders($token))->json('data.id');

        $platformPermissionId = Permission::query()->where('scope', 'platform')->value('id');
        $tenantPermissionId = Permission::query()->where('scope', 'tenant')->where('name', 'role.view')->value('id');

        $response = $this->postJson("/api/v1/app/roles/{$roleId}/permissions", [
            'permission_ids' => [$platformPermissionId, $tenantPermissionId],
        ], $this->authHeaders($token))->assertOk();

        $this->assertTrue(collect($response->json('data.permissions'))->contains('role.view'));
        $this->assertSame(1, Role::query()->findOrFail($roleId)->permissions()->count());
    }
}
