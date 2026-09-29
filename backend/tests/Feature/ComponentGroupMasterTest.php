<?php

namespace Tests\Feature;

use App\Domain\AccessControl\Models\Permission;
use App\Domain\AccessControl\Models\Role;
use App\Domain\Audit\Models\AuditLog;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\MasterData\Support\ComponentGroupBaseline;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\ProductCompatibility;
use Database\Seeders\ComponentGroupSeeder;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Component Group Master improvement: 3-letter abbreviation, user-managed
 * lifecycle (tenant + platform), production-safe seeder, and protection of
 * existing Products/SKUs and history.
 */
class ComponentGroupMasterTest extends TestCase
{
    private const MANAGE = ['component_group.view', 'component_group.create', 'component_group.update', 'component_group.delete'];

    private function tenantWithUser(array $permissions = self::MANAGE): array
    {
        $tenant = $this->makeTenant(['code' => 'CGM-'.Str::upper(Str::random(4))]);
        $this->grantModule($tenant, 'CORE');
        $this->grantModule($tenant, 'INVENTORY');
        [, $token] = $this->makeTenantUser($tenant, array_merge($permissions, ['product.view', 'product.update']));

        return [$tenant, $this->authHeaders($token)];
    }

    private function createGroup(array $headers, array $overrides = [])
    {
        return $this->postJson('/api/v1/app/component-groups', array_merge([
            'code' => 'CG-'.Str::upper(Str::random(6)),
            'name' => 'Custom Group',
            'abbreviation' => 'CUS',
        ], $overrides), $headers);
    }

    // ---------------------------------------------------------------- abbreviation rules

    public function test_abbreviation_validation_matrix(): void
    {
        [, $headers] = $this->tenantWithUser();

        $this->createGroup($headers, ['abbreviation' => 'ENG'])->assertCreated()->assertJsonPath('data.abbreviation', 'ENG');
        $this->createGroup($headers, ['abbreviation' => ' brk '])->assertCreated()->assertJsonPath('data.abbreviation', 'BRK');

        foreach (['EN', 'ENGG', 'E1G', 'EN-', '', 'ÉNG'] as $invalid) {
            $this->createGroup($headers, ['abbreviation' => $invalid])->assertStatus(422)->assertJsonValidationErrors('abbreviation');
        }
        $this->postJson('/api/v1/app/component-groups', ['code' => 'CG-NOABBR', 'name' => 'No abbreviation'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('abbreviation');

        $this->createGroup($headers, ['abbreviation' => 'ENG'])->assertStatus(422)->assertJsonValidationErrors('abbreviation');
        $this->createGroup($headers, ['abbreviation' => 'eng'])->assertStatus(422)->assertJsonValidationErrors('abbreviation');
    }

    public function test_tenant_abbreviation_cannot_reuse_platform_or_deleted_abbreviation_but_other_tenants_are_independent(): void
    {
        $platform = $this->makeComponentGroup(['abbreviation' => 'HYD', 'name' => 'Hydraulic System']);
        [, $headers] = $this->tenantWithUser();
        [, $otherHeaders] = $this->tenantWithUser();

        $this->createGroup($headers, ['abbreviation' => 'HYD'])->assertStatus(422)->assertJsonValidationErrors('abbreviation');

        $id = $this->createGroup($headers, ['abbreviation' => 'XYZ'])->assertCreated()->json('data.id');
        $this->deleteJson("/api/v1/app/component-groups/{$id}", [], $headers)->assertOk();
        // Soft-deleted abbreviation is never reused (it may be printed in historical SKUs).
        $this->createGroup($headers, ['abbreviation' => 'XYZ'])->assertStatus(422)->assertJsonValidationErrors('abbreviation');

        // A different tenant has its own SKU namespace.
        $this->createGroup($otherHeaders, ['abbreviation' => 'XYZ'])->assertCreated();
        $this->assertNotNull($platform->fresh());
    }

    public function test_database_enforces_abbreviation_format_and_uniqueness_including_soft_deleted_rows(): void
    {
        $this->assertDatabaseRejects(fn () => $this->makeComponentGroup(['abbreviation' => 'eng']));
        $this->assertDatabaseRejects(fn () => $this->makeComponentGroup(['abbreviation' => 'EN1']));

        $group = $this->makeComponentGroup(['abbreviation' => 'SWG']);
        $group->delete();
        $this->assertDatabaseRejects(fn () => $this->makeComponentGroup(['abbreviation' => 'SWG']));

        $tenant = $this->makeTenant();
        $this->makeComponentGroup(['tenant_id' => $tenant->id, 'is_system' => false, 'abbreviation' => 'QQQ']);
        $this->assertDatabaseRejects(fn () => $this->makeComponentGroup(['tenant_id' => $tenant->id, 'is_system' => false, 'abbreviation' => 'QQQ']));

        // Legacy rows without an abbreviation remain valid at the database level.
        $this->makeComponentGroup(['abbreviation' => null]);
        $this->makeComponentGroup(['abbreviation' => null]);
    }

    public function test_unused_abbreviation_is_editable_but_locked_once_used_by_a_product(): void
    {
        [$tenant, $headers] = $this->tenantWithUser();
        $id = $this->createGroup($headers, ['abbreviation' => 'BRK', 'name' => 'Brake System'])->json('data.id');

        $this->putJson("/api/v1/app/component-groups/{$id}", ['abbreviation' => 'bra'], $headers)
            ->assertOk()->assertJsonPath('data.abbreviation', 'BRA')->assertJsonPath('data.abbreviation_locked', false);

        $product = $this->makeProduct($tenant, null, null, ['sku' => 'SPR-BRA-000123']);
        $this->postJson("/api/v1/app/products/{$product->id}/component-groups", ['component_group_ids' => [$id]], $headers)->assertOk();

        $this->getJson("/api/v1/app/component-groups/{$id}", $headers)
            ->assertJsonPath('data.is_used', true)->assertJsonPath('data.abbreviation_locked', true);

        $this->putJson("/api/v1/app/component-groups/{$id}", ['abbreviation' => 'BRK'], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('abbreviation');
        // Resending the same value is not a change.
        $this->putJson("/api/v1/app/component-groups/{$id}", ['abbreviation' => 'BRA', 'name' => 'Braking System'], $headers)->assertOk();

        $this->assertSame('BRA', ComponentGroup::query()->find($id)->abbreviation);
        $this->assertSame('SPR-BRA-000123', $product->fresh()->sku);
    }

    public function test_legacy_group_without_abbreviation_can_have_one_filled_in_even_when_used(): void
    {
        [$tenant, $headers] = $this->tenantWithUser();
        $legacy = $this->makeComponentGroup(['tenant_id' => $tenant->id, 'is_system' => false, 'abbreviation' => null]);
        $product = $this->makeProduct($tenant);
        $product->componentGroups()->attach($legacy->id);

        $this->getJson('/api/v1/app/component-groups?search='.$legacy->code, $headers)
            ->assertJsonPath('data.0.abbreviation', null)->assertJsonPath('data.0.abbreviation_locked', false);

        $this->putJson("/api/v1/app/component-groups/{$legacy->id}", ['abbreviation' => 'LEG'], $headers)->assertOk();
        $this->putJson("/api/v1/app/component-groups/{$legacy->id}", ['abbreviation' => 'LEX'], $headers)->assertStatus(422);
    }

    // ---------------------------------------------------------------- SKU / history integrity

    public function test_rename_and_soft_delete_never_touch_existing_product_or_its_references(): void
    {
        [$tenant, $headers] = $this->tenantWithUser();
        $id = $this->createGroup($headers, ['abbreviation' => 'BRK', 'name' => 'Brake System'])->json('data.id');
        $product = $this->makeProduct($tenant, null, null, ['sku' => 'SPR-BRK-000001']);
        $product->componentGroups()->attach($id);
        ProductCompatibility::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'component_group_id' => $id]);

        $this->putJson("/api/v1/app/component-groups/{$id}", ['name' => 'Braking System'], $headers)->assertOk();
        $this->deleteJson("/api/v1/app/component-groups/{$id}", [], $headers)->assertOk();

        $group = ComponentGroup::withTrashed()->find($id);
        $this->assertTrue($group->trashed());
        $this->assertSame('INACTIVE', $group->status);
        $this->assertSame('BRK', $group->abbreviation);

        $fresh = Product::query()->find($product->id);
        $this->assertSame('SPR-BRK-000001', $fresh->sku);
        $this->assertSame($product->code, $fresh->code);

        // Historical product still resolves the (deleted) group.
        $show = $this->getJson("/api/v1/app/products/{$product->id}", $headers)->assertOk();
        $this->assertSame('BRK', $show->json('data.component_groups.0.abbreviation'));
        $this->assertSame('Braking System', $show->json('data.compatibilities.0.component_group.name'));

        // Not listed and not selectable for new data.
        $this->assertNotContains($id, collect($this->getJson('/api/v1/app/component-groups', $headers)->json('data'))->pluck('id'));
        $this->postJson("/api/v1/app/products/{$product->id}/compatibilities", ['component_group_id' => $id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('component_group_id');
        $other = $this->makeProduct($tenant);
        $this->postJson("/api/v1/app/products/{$other->id}/component-groups", ['component_group_ids' => [$id]], $headers)->assertStatus(422);

        // ...but the product that already holds it can resend its own classification.
        $this->postJson("/api/v1/app/products/{$product->id}/component-groups", ['component_group_ids' => [$id]], $headers)->assertOk();
        $this->assertTrue(DB::table('product_component_groups')->where('component_group_id', $id)->exists());
    }

    public function test_inactive_group_is_not_selectable_for_new_references(): void
    {
        [$tenant, $headers] = $this->tenantWithUser();
        $inactive = $this->makeComponentGroup(['abbreviation' => 'INA', 'status' => 'INACTIVE']);
        $product = $this->makeProduct($tenant);

        $this->postJson("/api/v1/app/products/{$product->id}/compatibilities", ['component_group_id' => $inactive->id], $headers)
            ->assertStatus(422)->assertJsonValidationErrors('component_group_id');
    }

    public function test_group_from_another_tenant_is_not_selectable(): void
    {
        [$tenant, $headers] = $this->tenantWithUser();
        $otherTenant = $this->makeTenant();
        $foreign = $this->makeComponentGroup(['tenant_id' => $otherTenant->id, 'is_system' => false, 'abbreviation' => 'FOR']);
        $product = $this->makeProduct($tenant);

        $this->postJson("/api/v1/app/products/{$product->id}/compatibilities", ['component_group_id' => $foreign->id], $headers)->assertStatus(422);
        $this->postJson("/api/v1/app/products/{$product->id}/component-groups", ['component_group_ids' => [$foreign->id]], $headers)->assertStatus(422);
    }

    public function test_referenced_group_cannot_be_physically_deleted(): void
    {
        $tenant = $this->makeTenant();
        $group = $this->makeComponentGroup(['abbreviation' => 'PHY']);
        $product = $this->makeProduct($tenant);
        $product->componentGroups()->attach($group->id);

        $this->assertDatabaseRejects(fn () => $group->forceDelete());
        $this->assertTrue(DB::table('product_component_groups')->where('component_group_id', $group->id)->exists());
    }

    // ---------------------------------------------------------------- lifecycle, permissions, audit

    public function test_soft_delete_restore_and_audit_trail(): void
    {
        [, $headers] = $this->tenantWithUser();
        $id = $this->createGroup($headers, ['abbreviation' => 'AUD', 'name' => 'Audited'])->json('data.id');

        $this->putJson("/api/v1/app/component-groups/{$id}", ['name' => 'Audited 2', 'abbreviation' => 'AUE'], $headers)->assertOk();
        $this->deleteJson("/api/v1/app/component-groups/{$id}", [], $headers)->assertOk();

        $this->getJson('/api/v1/app/component-groups?trashed=only', $headers)
            ->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.is_deleted', true);

        $this->postJson("/api/v1/app/component-groups/{$id}/restore", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.is_deleted', false);

        $actions = AuditLog::query()->where('resource_type', 'ComponentGroup')->where('resource_id', $id)->pluck('action')->all();
        foreach (['created', 'updated', 'deactivated', 'restored'] as $expected) {
            $this->assertContains($expected, $actions);
        }
        $abbreviationChange = AuditLog::query()->where('resource_id', $id)->where('action', 'updated')->get()
            ->first(fn ($log) => isset($log->new_values['abbreviation']));
        $this->assertSame('AUD', $abbreviationChange->old_values['abbreviation']);
        $this->assertSame('AUE', $abbreviationChange->new_values['abbreviation']);
    }

    public function test_delete_requires_the_granular_delete_permission_and_system_groups_stay_read_only_for_tenants(): void
    {
        [, $headers] = $this->tenantWithUser(['component_group.view', 'component_group.create', 'component_group.update']);
        $id = $this->createGroup($headers, ['abbreviation' => 'DEL'])->json('data.id');
        $this->deleteJson("/api/v1/app/component-groups/{$id}", [], $headers)->assertStatus(403);

        [, $managerHeaders] = $this->tenantWithUser();
        $system = $this->makeComponentGroup(['abbreviation' => 'SYS']);
        $this->putJson("/api/v1/app/component-groups/{$system->id}", ['name' => 'Hacked'], $managerHeaders)->assertStatus(403);
        $this->deleteJson("/api/v1/app/component-groups/{$system->id}", [], $managerHeaders)->assertStatus(403);
    }

    public function test_group_with_active_sub_groups_cannot_be_deleted(): void
    {
        [, $headers] = $this->tenantWithUser();
        $parentId = $this->createGroup($headers, ['abbreviation' => 'PAR'])->json('data.id');
        $this->createGroup($headers, ['abbreviation' => 'CHI', 'parent_id' => $parentId])->assertCreated();

        $this->deleteJson("/api/v1/app/component-groups/{$parentId}", [], $headers)->assertStatus(422);
    }

    public function test_index_exposes_abbreviation_and_usage_and_searches_by_abbreviation(): void
    {
        [$tenant, $headers] = $this->tenantWithUser();
        $used = $this->createGroup($headers, ['abbreviation' => 'USD', 'name' => 'Used'])->json('data.id');
        $this->createGroup($headers, ['abbreviation' => 'UNU', 'name' => 'Unused'])->assertCreated();
        $this->makeProduct($tenant)->componentGroups()->attach($used);

        $rows = collect($this->getJson('/api/v1/app/component-groups?search=u&sort=abbreviation', $headers)->json('data'))->keyBy('abbreviation');
        $this->assertTrue($rows['USD']['is_used']);
        $this->assertFalse($rows['UNU']['is_used']);
        $this->assertArrayHasKey('code', $rows['UNU']);
        $this->assertArrayHasKey('is_system', $rows['UNU']);

        $this->assertCount(1, $this->getJson('/api/v1/app/component-groups?search=usd', $headers)->json('data'));
    }

    public function test_platform_admin_manages_the_shared_baseline(): void
    {
        [, $token] = $this->makePlatformUser(['component_group.view', 'component_group.create', 'component_group.update', 'component_group.delete']);
        $headers = $this->authHeaders($token);
        $tenant = $this->makeTenant();
        $this->makeComponentGroup(['tenant_id' => $tenant->id, 'is_system' => false, 'abbreviation' => 'TNT']);

        // A platform group may not take an abbreviation some tenant already uses.
        $this->postJson('/api/v1/platform/component-groups', ['code' => 'CG-TNT', 'name' => 'X', 'abbreviation' => 'TNT'], $headers)->assertStatus(422);

        $id = $this->postJson('/api/v1/platform/component-groups', ['code' => 'CG-NEW', 'name' => 'New Group', 'abbreviation' => 'nwg'], $headers)
            ->assertCreated()->assertJsonPath('data.abbreviation', 'NWG')->assertJsonPath('data.is_system', true)->json('data.id');
        $this->assertNull(ComponentGroup::query()->find($id)->tenant_id);

        $this->putJson("/api/v1/platform/component-groups/{$id}", ['name' => 'Renamed', 'code' => 'CG-HACK'], $headers)
            ->assertOk()->assertJsonPath('data.name', 'Renamed')->assertJsonPath('data.code', 'CG-NEW');

        $this->deleteJson("/api/v1/platform/component-groups/{$id}", [], $headers)->assertOk();
        $this->postJson("/api/v1/platform/component-groups/{$id}/restore", [], $headers)->assertOk();

        // Tenant-owned rows are invisible to the platform endpoints.
        $tenantRow = ComponentGroup::query()->where('abbreviation', 'TNT')->first();
        $this->putJson("/api/v1/platform/component-groups/{$tenantRow->id}", ['name' => 'x'], $headers)->assertStatus(404);
        $this->assertNotContains('TNT', collect($this->getJson('/api/v1/platform/component-groups', $headers)->json('data'))->pluck('abbreviation'));
    }

    // ---------------------------------------------------------------- seeder

    public function test_seeder_creates_the_25_baseline_groups_with_correct_abbreviations(): void
    {
        $this->seed(ComponentGroupSeeder::class);

        $groups = ComponentGroup::query()->whereNull('tenant_id')->get()->keyBy('code');
        $this->assertCount(25, $groups);
        foreach (ComponentGroupBaseline::definitions() as $definition) {
            $group = $groups[$definition['code']];
            $this->assertSame($definition['abbreviation'], $group->abbreviation);
            $this->assertSame($definition['name'], $group->name);
            $this->assertTrue($group->is_system);
            $this->assertSame('ACTIVE', $group->status);
        }
        $this->assertSame($groups['CG-ENGINE']->id, $groups['CG-ENGINE-LUBE']->parent_id);
        $this->assertCount(25, $groups->pluck('abbreviation')->unique());
    }

    public function test_seeder_rerun_is_idempotent_and_respects_user_ownership(): void
    {
        $this->seed(MasterDataSeeder::class);

        $tenant = $this->makeTenant();
        $custom = $this->makeComponentGroup(['tenant_id' => $tenant->id, 'is_system' => false, 'abbreviation' => 'CUS', 'name' => 'Custom']);
        $customPlatform = $this->makeComponentGroup(['code' => 'CG-CUSTOM', 'abbreviation' => 'CPL', 'name' => 'Custom Platform']);

        $brake = ComponentGroup::query()->whereNull('tenant_id')->where('code', 'CG-BRAKE')->first();
        $brake->update(['name' => 'Braking System (curated)', 'description' => 'curated', 'status' => 'INACTIVE']);
        $hvac = ComponentGroup::query()->whereNull('tenant_id')->where('code', 'CG-HVAC')->first();
        $hvac->delete();

        $product = $this->makeProduct($tenant, null, null, ['sku' => 'SPR-BRK-000777']);
        $product->componentGroups()->attach($brake->id);

        $this->seed(MasterDataSeeder::class);
        $this->seed(MasterDataSeeder::class);

        $this->assertSame(26, ComponentGroup::withTrashed()->whereNull('tenant_id')->count());
        $brake->refresh();
        $this->assertSame('Braking System (curated)', $brake->name);
        $this->assertSame('curated', $brake->description);
        $this->assertSame('INACTIVE', $brake->status);
        $this->assertTrue(ComponentGroup::withTrashed()->find($hvac->id)->trashed(), 'Seeder must not restore a soft-deleted group.');
        $this->assertSame(1, ComponentGroup::withTrashed()->where('code', 'CG-HVAC')->count(), 'Seeder must not recreate a soft-deleted group.');
        $this->assertSame('Custom', $custom->fresh()->name);
        $this->assertNotNull($customPlatform->fresh());
        $this->assertSame('SPR-BRK-000777', $product->fresh()->sku);
        $this->assertTrue($product->componentGroups()->where('component_groups.id', $brake->id)->exists());
    }

    public function test_seeder_fills_only_missing_abbreviation_and_skips_colliding_baseline(): void
    {
        $legacy = $this->makeComponentGroup(['code' => 'CG-SWING', 'name' => 'Swing (curated)', 'abbreviation' => null]);
        $tenant = $this->makeTenant();
        // A tenant already owns "GLS": the baseline Glass & Washer group is skipped, never duplicated.
        $this->makeComponentGroup(['tenant_id' => $tenant->id, 'is_system' => false, 'abbreviation' => 'GLS']);

        $this->seed(ComponentGroupSeeder::class);

        $legacy->refresh();
        $this->assertSame('SWG', $legacy->abbreviation);
        $this->assertSame('Swing (curated)', $legacy->name);
        $this->assertFalse(ComponentGroup::query()->whereNull('tenant_id')->where('code', 'CG-GLASS')->exists());
        $this->assertSame(1, ComponentGroup::query()->where('abbreviation', 'GLS')->count());
    }

    // ---------------------------------------------------------------- migrations

    public function test_backfill_migration_maps_by_code_and_renames_only_uncurated_legacy_names(): void
    {
        $clutch = $this->makeComponentGroup(['code' => 'CG-CLUTCH', 'name' => 'Clutch System / Torque Converter', 'abbreviation' => null]);
        $tyre = $this->makeComponentGroup(['code' => 'CG-TYRE', 'name' => 'Tyres (curated)', 'abbreviation' => null]);
        $engineId = $this->makeComponentGroup(['code' => 'CG-ENGINE', 'name' => 'Engine', 'abbreviation' => null])->id;
        $tenant = $this->makeTenant();
        $tenantBrake = $this->makeComponentGroup(['tenant_id' => $tenant->id, 'is_system' => false, 'code' => 'CG-BRAKE', 'name' => 'Brake', 'abbreviation' => null]);
        // Ambiguous: the same platform code twice (active + soft-deleted twin) — must not be guessed.
        $this->makeComponentGroup(['code' => 'CG-HYD', 'abbreviation' => null])->delete();
        $this->makeComponentGroup(['code' => 'CG-HYD', 'abbreviation' => null]);

        $migration = require database_path('migrations/2026_09_29_000002_backfill_component_group_baseline_abbreviations.php');
        $migration->up();
        $migration->up();

        $this->assertSame(['CLT', 'Clutch & Torque Converter'], [$clutch->fresh()->abbreviation, $clutch->fresh()->name]);
        $this->assertSame(['WTY', 'Tyres (curated)'], [$tyre->fresh()->abbreviation, $tyre->fresh()->name]);
        $this->assertSame($engineId, ComponentGroup::query()->where('abbreviation', 'ENG')->value('id'));
        $this->assertNull($tenantBrake->fresh()->abbreviation, 'Tenant-owned groups are never auto-assigned an abbreviation.');
        $this->assertSame(0, ComponentGroup::withTrashed()->where('code', 'CG-HYD')->whereNotNull('abbreviation')->count());
    }

    public function test_delete_permission_backfill_grants_every_role_that_could_update(): void
    {
        $tenant = $this->makeTenant();
        $update = Permission::query()->where('name', 'component_group.update')->where('scope', 'tenant')->first();
        $delete = Permission::query()->where('name', 'component_group.delete')->where('scope', 'tenant')->first();
        $role = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Legacy Editor', 'scope' => 'tenant', 'is_system' => false]);
        $role->permissions()->sync([$update->id]);
        $viewer = Role::query()->create(['tenant_id' => $tenant->id, 'name' => 'Viewer', 'scope' => 'tenant', 'is_system' => false]);

        $migration = require database_path('migrations/2026_09_29_000004_grant_component_group_delete_permission.php');
        $migration->up();
        $migration->up();

        $this->assertTrue($role->permissions()->where('permissions.id', $delete->id)->exists());
        $this->assertFalse($viewer->permissions()->where('permissions.id', $delete->id)->exists());
        $this->assertSame(1, DB::table('role_permissions')->where('role_id', $role->id)->where('permission_id', $delete->id)->count());
    }

    private function assertDatabaseRejects(callable $callback): void
    {
        try {
            DB::transaction($callback);
        } catch (QueryException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Expected the database to reject the write.');
    }
}
