<?php

namespace Tests\Feature;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\AccessControl\Models\RoleAssignment;
use App\Models\User;
use Database\Seeders\PlatformSuperadminRoleSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

/**
 * System-defined roles are identified by the canonical `roles.code` (PLATFORM_SUPERADMIN), never by their
 * display name. Tenant-created roles keep code = null. The migration is additive: names, permissions and
 * user-role assignments do not change.
 */
class SystemRoleCodeTest extends TestCase
{
    private function migration(): object
    {
        return require database_path('migrations/2026_10_13_000001_add_code_to_roles.php');
    }

    /** A role as it existed before roles had codes, with permissions and an assigned user. */
    private function legacySuperadmin(): array
    {
        $role = Role::query()->create(['tenant_id' => null, 'name' => 'Platform Superadmin', 'scope' => 'platform', 'is_system' => true, 'description' => 'Full platform access.']);
        $permissionIds = Permission::query()->where('scope', 'platform')->limit(5)->pluck('id')->sort()->values()->all();
        $role->permissions()->sync($permissionIds);
        $user = User::query()->create(['name' => 'Legacy Admin', 'email' => 'legacy-'.Str::random(6).'@optifleet.test', 'password' => 'x', 'user_type' => 'platform', 'status' => 'active']);
        RoleAssignment::query()->create(['user_id' => $user->id, 'tenant_id' => null, 'role_id' => $role->id]);

        return [$role, $permissionIds, $user];
    }

    public function test_a_platform_superadmin_is_seeded_with_its_canonical_code(): void
    {
        $this->seed(PlatformSuperadminRoleSeeder::class);
        $this->seed(PlatformSuperadminRoleSeeder::class);

        $role = Role::platformSuperadmin();
        $this->assertNotNull($role);
        $this->assertSame(['PLATFORM_SUPERADMIN', 'Platform Superadmin', 'platform', true], [$role->code, $role->name, $role->scope, $role->is_system]);
        $this->assertSame(1, Role::query()->whereNull('tenant_id')->where('code', Role::PLATFORM_SUPERADMIN)->count(), 'Reseeding creates no duplicate.');
        $this->assertSame(Permission::query()->where('scope', 'platform')->count(), $role->permissions()->count());
    }

    public function test_b_tenant_custom_roles_have_no_code(): void
    {
        $tenant = $this->makeTenant(['code' => 'SRC-'.Str::random(4)]);
        $this->grantModule($tenant, 'ACCESS_MANAGEMENT');
        [, $token] = $this->makeTenantUser($tenant, ['role.create', 'role.view']);

        $created = $this->postJson('/api/v1/app/roles', ['name' => 'Fleet Supervisor Bandung', 'code' => 'HACKED'], $this->authHeaders($token));
        $created->assertCreated();
        $this->assertNull($created->json('data.code'), 'The API cannot set a role code.');
        $this->assertNull(Role::query()->findOrFail($created->json('data.id'))->code);
    }

    public function test_c_a_code_is_unique_within_its_namespace(): void
    {
        $this->seed(PlatformSuperadminRoleSeeder::class);
        try {
            DB::transaction(fn () => Role::query()->create(['tenant_id' => null, 'code' => Role::PLATFORM_SUPERADMIN, 'name' => 'Another', 'scope' => 'platform', 'is_system' => true]));
            $this->fail('A second PLATFORM_SUPERADMIN must be rejected.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('roles_code_unique', $e->getMessage());
        }

        // A per-tenant system code exists once per tenant.
        [$a, $b] = [$this->makeTenant(['code' => 'SRA-'.Str::random(4)]), $this->makeTenant(['code' => 'SRB-'.Str::random(4)])];
        Role::query()->create(['tenant_id' => $a->id, 'code' => 'TENANT_ADMIN', 'name' => 'Tenant Admin', 'scope' => 'tenant', 'is_system' => true]);
        Role::query()->create(['tenant_id' => $b->id, 'code' => 'TENANT_ADMIN', 'name' => 'Tenant Admin', 'scope' => 'tenant', 'is_system' => true]);
        $this->expectException(QueryException::class);
        Role::query()->create(['tenant_id' => $a->id, 'code' => 'TENANT_ADMIN', 'name' => 'Tenant Admin 2', 'scope' => 'tenant', 'is_system' => true]);
    }

    public function test_d_e_backfill_keeps_name_permissions_and_assignments_and_is_idempotent(): void
    {
        [$role, $permissionIds, $user] = $this->legacySuperadmin();
        $assignments = RoleAssignment::query()->where('role_id', $role->id)->pluck('user_id')->all();

        $this->migration()->up();
        $this->migration()->up();

        $role->refresh();
        $this->assertSame([Role::PLATFORM_SUPERADMIN, 'Platform Superadmin'], [$role->code, $role->name]);
        $this->assertSame($permissionIds, $role->permissions()->pluck('permissions.id')->sort()->values()->all());
        $this->assertSame($assignments, RoleAssignment::query()->where('role_id', $role->id)->pluck('user_id')->all());
        $this->assertSame([$user->id], $assignments);

        // The seeder adopts the existing role instead of creating a second one.
        $this->seed(PlatformSuperadminRoleSeeder::class);
        $this->assertSame([$role->id], Role::query()->whereNull('tenant_id')->where('scope', 'platform')->where('is_system', true)->pluck('id')->all());
    }

    public function test_backfill_does_not_guess_when_the_match_is_ambiguous(): void
    {
        $this->legacySuperadmin();
        $this->legacySuperadmin();

        $this->migration()->up();

        $this->assertSame(0, Role::query()->where('code', Role::PLATFORM_SUPERADMIN)->count());
    }

    public function test_a_code_is_immutable_and_machine_readable(): void
    {
        $this->seed(PlatformSuperadminRoleSeeder::class);
        $role = Role::platformSuperadmin();

        try {
            $role->forceFill(['code' => 'SUPERADMIN'])->save();
            $this->fail('A system role code must not change.');
        } catch (LogicException) {
            $this->assertSame(Role::PLATFORM_SUPERADMIN, $role->fresh()->code);
        }
        // Renaming the display text does not change the identity.
        $role->fresh()->forceFill(['name' => 'Superadmin Platform'])->save();
        $this->assertSame('Superadmin Platform', Role::platformSuperadmin()->name);

        $this->expectException(LogicException::class);
        Role::query()->create(['tenant_id' => null, 'code' => 'Platform Superadmin', 'name' => 'X', 'scope' => 'platform']);
    }

    public function test_the_platform_admin_command_finds_the_role_by_code_not_name(): void
    {
        $this->seed(PlatformSuperadminRoleSeeder::class);
        Role::platformSuperadmin()->forceFill(['name' => 'Superadmin Platform'])->save();

        $this->artisan('platform:create-admin', ['--email' => 'root@optifleet.test', '--password' => 'a-long-password-123'])->assertSuccessful();

        $user = User::query()->where('email', 'root@optifleet.test')->firstOrFail();
        $this->assertTrue(RoleAssignment::query()->where('user_id', $user->id)->where('role_id', Role::platformSuperadmin()->id)->exists());
    }
}
