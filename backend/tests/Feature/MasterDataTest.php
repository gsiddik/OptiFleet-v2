<?php

namespace Tests\Feature;

use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\MasterData\Models\VehicleCategory;
use Tests\TestCase;

class MasterDataTest extends TestCase
{
    public function test_tenant_can_create_and_view_own_vehicle_category(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'CORE');
        [, $token] = $this->makeTenantUser($tenant, ['vehicle_category.view', 'vehicle_category.create']);

        $response = $this->postJson('/api/v1/app/vehicle-categories', [
            'code' => 'VC-CUSTOM',
            'name' => 'Custom Category',
        ], $this->authHeaders($token));
        $response->assertStatus(201);

        $this->getJson('/api/v1/app/vehicle-categories', $this->authHeaders($token))
            ->assertOk()
            ->assertJsonFragment(['code' => 'VC-CUSTOM']);
    }

    public function test_tenant_sees_platform_system_master_data_too(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'CORE');
        VehicleCategory::query()->create(['tenant_id' => null, 'code' => 'VC-SYS1', 'name' => 'System Cat', 'is_system' => true, 'status' => 'ACTIVE']);

        [, $token] = $this->makeTenantUser($tenant, ['vehicle_category.view']);

        $this->getJson('/api/v1/app/vehicle-categories', $this->authHeaders($token))
            ->assertOk()
            ->assertJsonFragment(['code' => 'VC-SYS1']);
    }

    public function test_tenant_cannot_modify_system_master_data(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'CORE');
        $system = VehicleCategory::query()->create(['tenant_id' => null, 'code' => 'VC-SYS2', 'name' => 'System Cat 2', 'is_system' => true, 'status' => 'ACTIVE']);

        [, $token] = $this->makeTenantUser($tenant, ['vehicle_category.view', 'vehicle_category.update']);

        $this->putJson("/api/v1/app/vehicle-categories/{$system->id}", ['name' => 'Hacked'], $this->authHeaders($token))
            ->assertStatus(403);

        $this->deleteJson("/api/v1/app/vehicle-categories/{$system->id}", [], $this->authHeaders($token))
            ->assertStatus(403);
    }

    public function test_tenant_cannot_see_or_edit_another_tenants_custom_master_data(): void
    {
        $tenantA = $this->makeTenant(['code' => 'MD-A']);
        $tenantB = $this->makeTenant(['code' => 'MD-B']);
        $this->grantModule($tenantA, 'CORE');
        $this->grantModule($tenantB, 'CORE');

        $categoryA = VehicleCategory::query()->create(['tenant_id' => $tenantA->id, 'code' => 'VC-A1', 'name' => 'A Only', 'is_system' => false, 'status' => 'ACTIVE']);

        [, $tokenB] = $this->makeTenantUser($tenantB, ['vehicle_category.view', 'vehicle_category.update']);

        $this->getJson("/api/v1/app/vehicle-categories/{$categoryA->id}", $this->authHeaders($tokenB))->assertStatus(404);
        $this->putJson("/api/v1/app/vehicle-categories/{$categoryA->id}", ['name' => 'x'], $this->authHeaders($tokenB))->assertStatus(404);
    }

    public function test_vehicle_category_component_group_mapping_both_directions(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'CORE');

        $category = VehicleCategory::query()->create(['tenant_id' => $tenant->id, 'code' => 'VC-MAP', 'name' => 'Mapped Cat', 'is_system' => false, 'status' => 'ACTIVE']);
        $group1 = ComponentGroup::query()->create(['tenant_id' => null, 'code' => 'CG-MAP1', 'name' => 'Group 1', 'sequence' => 1, 'is_system' => true, 'status' => 'ACTIVE']);
        $group2 = ComponentGroup::query()->create(['tenant_id' => null, 'code' => 'CG-MAP2', 'name' => 'Group 2', 'sequence' => 2, 'is_system' => true, 'status' => 'ACTIVE']);

        [, $token] = $this->makeTenantUser($tenant, ['vehicle_category.view', 'component_group.view', 'component_group.map']);

        $this->postJson("/api/v1/app/vehicle-categories/{$category->id}/component-groups", [
            'component_group_ids' => [$group1->id, $group2->id],
        ], $this->authHeaders($token))->assertOk();

        $this->assertCount(2, $category->componentGroups()->get());

        // now check the reverse direction reflects the same relation
        $response = $this->getJson("/api/v1/app/component-groups/{$group1->id}", $this->authHeaders($token));
        $response->assertOk();
        $categoryIds = collect($response->json('data.vehicle_categories'))->pluck('id');
        $this->assertTrue($categoryIds->contains($category->id));
    }

    public function test_system_component_group_hierarchy_is_dynamic_not_hardcoded(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'CORE');

        $parent = ComponentGroup::query()->create(['tenant_id' => null, 'code' => 'CG-P1', 'name' => 'Parent', 'sequence' => 1, 'is_system' => true, 'status' => 'ACTIVE']);
        ComponentGroup::query()->create(['tenant_id' => null, 'code' => 'CG-C1', 'name' => 'Child', 'parent_id' => $parent->id, 'sequence' => 1, 'is_system' => true, 'status' => 'ACTIVE']);

        [, $token] = $this->makeTenantUser($tenant, ['component_group.view']);

        $response = $this->getJson("/api/v1/app/component-groups/{$parent->id}", $this->authHeaders($token));
        $response->assertOk();
        $this->assertCount(1, $response->json('data.children'));
    }
}
