<?php

namespace Tests\Feature;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Models\RoleAssignment;
use App\Domain\AccessControl\Services\PermissionService;
use App\Domain\Audit\Models\AuditLog;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\PlatformSuperadminRoleSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Role permission management: authorized administrators maintain the permission set
 * of EXISTING roles (including seeded system roles) through the backend, which stays
 * the security boundary.
 */
class RolePermissionManagementTest extends TestCase
{
    private function ids(array $names, string $scope = 'tenant'): array
    {
        return Permission::query()->where('scope', $scope)->whereIn('name', $names)->pluck('id')->all();
    }

    private function adminFor($tenant): array
    {
        $this->grantModule($tenant, 'ACCESS_MANAGEMENT');
        [$user, $token] = $this->makeTenantUser($tenant, ['role.view', 'role.create', 'role.update', 'role.assign_permission']);

        return [$user, $this->authHeaders($token)];
    }

    private function systemRole($tenant, array $permissionNames): Role
    {
        $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Mechanic', 'scope' => 'tenant', 'is_system' => true, 'description' => 'Seeded']);
        $role->permissions()->sync($this->ids($permissionNames));

        return $role;
    }

    public function test_existing_system_role_permissions_can_be_edited_exactly(): void
    {
        $tenant = $this->makeTenant();
        [, $headers] = $this->adminFor($tenant);
        $role = $this->systemRole($tenant, ['product.view']);

        $response = $this->postJson("/api/v1/app/roles/{$role->id}/permissions", ['permission_ids' => $this->ids(['product.create'])], $headers)
            ->assertOk();

        $this->assertSame(['product.create'], $response->json('data.permissions'));
        $this->assertSame(['product.create'], $role->permissions()->pluck('name')->all());
        $this->assertSame(1, DB::table('role_permissions')->where('role_id', $role->id)->count(), 'No duplicate pivot rows.');

        // Re-sending the same selection is idempotent; an empty selection clears it.
        $this->postJson("/api/v1/app/roles/{$role->id}/permissions", ['permission_ids' => $this->ids(['product.create', 'product.create'])], $headers)->assertOk();
        $this->assertSame(1, DB::table('role_permissions')->where('role_id', $role->id)->count());
        $this->postJson("/api/v1/app/roles/{$role->id}/permissions", ['permission_ids' => []], $headers)->assertOk();
        $this->assertSame(0, $role->permissions()->count());

        $log = AuditLog::query()->where('resource_type', 'Role')->where('resource_id', $role->id)->where('action', 'permissions_changed')->oldest('created_at')->firstOrFail();
        $this->assertSame(['product.view'], $log->old_values['permissions']);
        $this->assertSame(['product.create'], $log->new_values['permissions']);
    }

    public function test_system_role_description_is_editable_but_its_name_is_fixed(): void
    {
        $tenant = $this->makeTenant();
        [, $headers] = $this->adminFor($tenant);
        $role = $this->systemRole($tenant, []);

        $this->putJson("/api/v1/app/roles/{$role->id}", ['description' => 'Workshop technicians'], $headers)
            ->assertOk()->assertJsonPath('data.description', 'Workshop technicians');
        $this->putJson("/api/v1/app/roles/{$role->id}", ['name' => 'Renamed'], $headers)->assertStatus(422);

        $custom = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Custom', 'scope' => 'tenant', 'is_system' => false]);
        $this->putJson("/api/v1/app/roles/{$custom->id}", ['name' => 'Custom 2'], $headers)->assertOk()->assertJsonPath('data.name', 'Custom 2');
    }

    public function test_unauthorized_users_cannot_change_roles_or_permissions(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ACCESS_MANAGEMENT');
        $role = $this->systemRole($tenant, ['product.view']);
        [, $viewer] = $this->makeTenantUser($tenant, ['role.view']);
        $headers = $this->authHeaders($viewer);

        $this->postJson("/api/v1/app/roles/{$role->id}/permissions", ['permission_ids' => $this->ids(['role.assign_permission'])], $headers)->assertStatus(403);
        $this->putJson("/api/v1/app/roles/{$role->id}", ['description' => 'x'], $headers)->assertStatus(403);
        $this->assertSame(['product.view'], $role->permissions()->pluck('name')->all());
    }

    public function test_unknown_permission_ids_are_rejected(): void
    {
        $tenant = $this->makeTenant();
        [, $headers] = $this->adminFor($tenant);
        $role = $this->systemRole($tenant, ['product.view']);

        $this->postJson("/api/v1/app/roles/{$role->id}/permissions", ['permission_ids' => [(string) Str::uuid()]], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('permission_ids');
        $this->assertSame(['product.view'], $role->permissions()->pluck('name')->all());
    }

    public function test_administrator_cannot_lock_themselves_out_of_role_management(): void
    {
        $tenant = $this->makeTenant();
        [$user, $headers] = $this->adminFor($tenant);
        $ownRole = RoleAssignment::query()->where('user_id', $user->id)->where('tenant_id', $tenant->id)->firstOrFail()->role;

        $this->postJson("/api/v1/app/roles/{$ownRole->id}/permissions", ['permission_ids' => $this->ids(['role.view'])], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('permission_ids');
        $this->assertTrue($ownRole->permissions()->where('name', 'role.assign_permission')->exists());

        // Keeping role.assign_permission while dropping others is fine.
        $this->postJson("/api/v1/app/roles/{$ownRole->id}/permissions", ['permission_ids' => $this->ids(['role.view', 'role.assign_permission'])], $headers)->assertOk();
    }

    public function test_permission_change_applies_on_the_next_request_without_relogin(): void
    {
        $tenant = $this->makeTenant();
        [, $adminHeaders] = $this->adminFor($tenant);
        $this->grantModule($tenant, 'INVENTORY');
        $role = $this->systemRole($tenant, ['product.view']);
        [$member, $memberToken] = $this->makeTenantUser($tenant);
        RoleAssignment::query()->create(['user_id' => $member->id, 'tenant_id' => $tenant->id, 'role_id' => $role->id]);
        $memberHeaders = $this->authHeaders($memberToken);

        $this->getJson('/api/v1/app/products', $memberHeaders)->assertOk();
        $this->assertTrue(app(PermissionService::class)->userHasPermission($member, 'product.view', $tenant->id)); // warm the cache

        $this->postJson("/api/v1/app/roles/{$role->id}/permissions", ['permission_ids' => []], $adminHeaders)->assertOk();

        $this->getJson('/api/v1/app/products', $memberHeaders)->assertStatus(403);
        $this->assertNotContains('product.view', $this->getJson('/api/v1/auth/me', $memberHeaders)->json('data.permissions') ?? []);
    }

    public function test_permissions_endpoint_exposes_module_feature_action_metadata(): void
    {
        $tenant = $this->makeTenant();
        [, $headers] = $this->adminFor($tenant);

        $rows = collect($this->getJson('/api/v1/app/permissions', $headers)->assertOk()->json('data'))->keyBy('name');
        $this->assertSame(Permission::query()->where('scope', 'tenant')->count(), $rows->count());
        $this->assertSame(['WORK_ORDER', 'work_order', 'approve'], [$rows['work_order.approve']['module'], $rows['work_order.approve']['feature'], $rows['work_order.approve']['action']]);
        $this->assertSame('INVENTORY', $rows['product.create']['module']);
        $this->assertSame('Access Management', $rows['role.update']['module_name']);

        // Third-party Service Invoice vs External Workshop Invoice: clearer labels, unchanged keys.
        $this->assertSame(['workshop_invoice', 'Service Invoice'], [$rows['workshop_invoice.view']['feature'], $rows['workshop_invoice.view']['feature_name']]);
        $this->assertSame('View External Workshop Invoice Reference', $rows['work_order.view_workshop_invoice_reference']['action_name']);
    }

    public function test_platform_superadmin_role_stays_locked_and_platform_roles_are_manageable(): void
    {
        $this->seed(PlatformSuperadminRoleSeeder::class);
        [, $token] = $this->makePlatformUser(['role.view', 'role.create', 'role.update', 'role.assign_permission']);
        $headers = $this->authHeaders($token);
        $superadmin = Role::query()->where('scope', 'platform')->where('is_system', true)->firstOrFail();

        $this->postJson("/api/v1/platform/roles/{$superadmin->id}/permissions", ['permission_ids' => []], $headers)->assertStatus(422);
        $this->assertSame(Permission::query()->where('scope', 'platform')->count(), $superadmin->permissions()->count());

        $platformIds = $this->ids(['tenant.view'], 'platform');
        $roleId = $this->postJson('/api/v1/platform/roles', ['name' => 'Support', 'permission_ids' => $platformIds], $headers)->assertCreated()->json('data.id');
        $this->postJson("/api/v1/platform/roles/{$roleId}/permissions", ['permission_ids' => $this->ids(['product.view'])], $headers)->assertStatus(422);
        $this->assertSame(['tenant.view'], Role::query()->find($roleId)->permissions()->pluck('name')->all());

        $scoped = collect($this->getJson('/api/v1/platform/permissions?scope=platform', $headers)->assertOk()->json('data'));
        $this->assertTrue($scoped->every(fn ($p) => $p['scope'] === 'platform'));
    }

    public function test_permission_seeder_is_idempotent_and_keeps_role_assignments(): void
    {
        $tenant = $this->makeTenant();
        $role = $this->systemRole($tenant, ['product.view', 'work_order.reject']);
        $before = Permission::query()->count();

        $this->seed(PermissionSeeder::class);
        $this->seed(PermissionSeeder::class);

        $this->assertSame($before, Permission::query()->count());
        $this->assertSame($before, Permission::query()->get()->unique(fn ($p) => $p->scope.'|'.$p->name)->count());
        $this->assertEqualsCanonicalizing(['product.view', 'work_order.reject'], $role->permissions()->pluck('name')->all());
    }

    public function test_work_order_reject_uses_its_own_permission_and_is_backfilled(): void
    {
        $tenant = $this->makeTenant();
        $approver = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Approver', 'scope' => 'tenant', 'is_system' => false]);
        $approver->permissions()->sync($this->ids(['work_order.approve']));

        $migration = require database_path('migrations/2026_09_30_000001_grant_work_order_reject_permission.php');
        $migration->up();
        $migration->up();

        $this->assertEqualsCanonicalizing(['work_order.approve', 'work_order.reject'], $approver->permissions()->pluck('name')->all());
        $route = collect(app('router')->getRoutes())->first(fn ($r) => $r->uri() === 'api/v1/app/work-orders/{workOrder}/reject');
        $this->assertContains('permission:work_order.reject', $route->gatherMiddleware());
    }
}
