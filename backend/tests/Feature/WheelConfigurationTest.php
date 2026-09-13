<?php

namespace Tests\Feature;

use App\Domain\Tire\Models\WheelConfiguration;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Final reconciliation: WheelConfiguration's create/list endpoints existed
 * but update/destroy did not, despite the frontend already anticipating a
 * `vehicle_category` eager-loaded relation it never actually received.
 */
class WheelConfigurationTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'WCFG-'.Str::random(4)]);
        $this->grantModule($tenant, 'TIRE');
        [, $token] = $this->makeTenantUser($tenant, ['tire.view', 'tire.manage']);

        return [$tenant, $token];
    }

    public function test_wheel_configuration_can_be_updated_and_deleted(): void
    {
        [$tenant, $token] = $this->setUpTenant();
        $category = $this->makeVehicleCategory();
        $headers = $this->authHeaders($token);

        $config = WheelConfiguration::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_category_id' => $category->id,
            'position_code' => 'FRONT_LEFT', 'label' => 'Front Left', 'axle_number' => 1, 'sequence' => 1,
        ]);

        $this->putJson("/api/v1/app/wheel-configurations/{$config->id}", ['label' => 'Front Left (Steer)'], $headers)
            ->assertOk()->assertJsonPath('data.label', 'Front Left (Steer)');

        $this->deleteJson("/api/v1/app/wheel-configurations/{$config->id}", [], $headers)->assertOk();
        $this->assertDatabaseMissing('wheel_configurations', ['id' => $config->id]);
    }

    public function test_platform_default_wheel_configuration_cannot_be_modified_by_a_tenant(): void
    {
        [, $token] = $this->setUpTenant();
        $category = $this->makeVehicleCategory();
        $platformConfig = WheelConfiguration::query()->create([
            'tenant_id' => null, 'vehicle_category_id' => $category->id,
            'position_code' => 'REAR_LEFT', 'label' => 'Rear Left', 'axle_number' => 2, 'sequence' => 3,
        ]);

        $headers = $this->authHeaders($token);
        $this->putJson("/api/v1/app/wheel-configurations/{$platformConfig->id}", ['label' => 'Hacked'], $headers)->assertStatus(404);
        $this->deleteJson("/api/v1/app/wheel-configurations/{$platformConfig->id}", [], $headers)->assertStatus(404);
    }

    public function test_wheel_configuration_is_tenant_isolated_on_update(): void
    {
        [, $token] = $this->setUpTenant();
        $otherTenant = $this->makeTenant(['code' => 'WCFGB-'.Str::random(4)]);
        $category = $this->makeVehicleCategory();
        $foreignConfig = WheelConfiguration::query()->create([
            'tenant_id' => $otherTenant->id, 'vehicle_category_id' => $category->id,
            'position_code' => 'REAR_RIGHT', 'label' => 'Rear Right', 'axle_number' => 2, 'sequence' => 4,
        ]);

        $this->putJson("/api/v1/app/wheel-configurations/{$foreignConfig->id}", ['label' => 'Hacked'], $this->authHeaders($token))->assertStatus(404);
    }

    public function test_wheel_configuration_search_and_eager_loads_category(): void
    {
        [$tenant, $token] = $this->setUpTenant();
        $category = $this->makeVehicleCategory(['name' => 'Truck 6x4']);
        WheelConfiguration::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_category_id' => $category->id,
            'position_code' => 'FRONT_LEFT', 'label' => 'Front Left', 'axle_number' => 1, 'sequence' => 1,
        ]);
        WheelConfiguration::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_category_id' => $category->id,
            'position_code' => 'FRONT_RIGHT', 'label' => 'Front Right', 'axle_number' => 1, 'sequence' => 2,
        ]);

        $response = $this->getJson('/api/v1/app/wheel-configurations?search=Front Left', $this->authHeaders($token))->assertOk();
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Truck 6x4', $response->json('data.0.vehicle_category.name'));
    }

    public function test_wheel_configuration_update_requires_permission(): void
    {
        $tenant = $this->makeTenant(['code' => 'WCFGC-'.Str::random(4)]);
        $this->grantModule($tenant, 'TIRE');
        $category = $this->makeVehicleCategory();
        $config = WheelConfiguration::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_category_id' => $category->id,
            'position_code' => 'FRONT_LEFT', 'label' => 'Front Left', 'axle_number' => 1, 'sequence' => 1,
        ]);
        [, $token] = $this->makeTenantUser($tenant, ['tire.view']);

        $this->putJson("/api/v1/app/wheel-configurations/{$config->id}", ['label' => 'X'], $this->authHeaders($token))->assertStatus(403);
    }
}
