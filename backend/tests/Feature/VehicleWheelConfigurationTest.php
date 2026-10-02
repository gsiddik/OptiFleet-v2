<?php

namespace Tests\Feature;

use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Vehicle Detail → Wheels Configuration (read side) and the "configurations compatible with this vehicle" list filter. */
class VehicleWheelConfigurationTest extends TestCase
{
    private const MASTERS = '/api/v1/app/wheel-configuration-masters';

    private function scenario(array $permissions = ['tire.view', 'tire.manage', 'tire.install', 'wheel_configuration.map_vehicle']): array
    {
        $tenant = $this->makeTenant(['code' => 'VWC-'.Str::random(4)]);
        foreach (['VEHICLE', 'INVENTORY', 'TIRE'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        [, $token] = $this->makeTenantUser($tenant, $permissions);

        return [$tenant, $branch, $this->makeVehicleCategory(), $this->authHeaders($token)];
    }

    private function car($tenant, $branch, $category, int $axles = 2, int $wheels = 6): Vehicle
    {
        return $this->makeVehicle($tenant, $branch, $category, ['vehicle_type' => 'Car', 'axle_count' => $axles, 'wheel_count' => $wheels]);
    }

    public function test_vehicle_without_configuration_returns_an_empty_mapping(): void
    {
        [$tenant, $branch, $category, $headers] = $this->scenario();
        $vehicle = $this->car($tenant, $branch, $category);

        $this->getJson("/api/v1/app/vehicles/{$vehicle->id}/wheel-configuration", $headers)->assertOk()
            ->assertJsonPath('data.mapping', null)
            ->assertJsonPath('data.installations', [])
            ->assertJsonPath('data.history', [])
            ->assertJsonPath('data.vehicle.vehicle_type', 'PASSENGER_CAR');
    }

    public function test_mapped_vehicle_shows_its_mapped_version_not_the_latest(): void
    {
        [$tenant, $branch, $category, $headers] = $this->scenario();
        $vehicle = $this->car($tenant, $branch, $category);
        $id = $this->postJson(self::MASTERS, ['vehicle_type' => 'PASSENGER_CAR', 'front_axles' => [1], 'rear_axles' => [2], 'spare_tires' => 0], $headers)->json('data.master.id');
        $this->putJson(self::MASTERS."/{$id}/vehicle-mappings", ['add_vehicle_ids' => [$vehicle->id], 'remove_vehicle_ids' => []], $headers)->assertOk();

        $res = $this->getJson("/api/v1/app/vehicles/{$vehicle->id}/wheel-configuration", $headers)->assertOk();
        $res->assertJsonPath('data.mapping.version.config_code', '1.2')
            ->assertJsonPath('data.mapping.version.total_axles', 2)
            ->assertJsonPath('data.mapping.version.total_wheels', 6)
            ->assertJsonPath('data.mapping.master.vehicle_type', 'PASSENGER_CAR')
            ->assertJsonPath('data.mapping.is_current_version', true);
        $this->assertSame(['1FL1', '1FR1', '1RL1', '1RL2', '1RR1', '1RR2'], array_column($res->json('data.mapping.version.positions'), 'position_code'));

        $this->putJson(self::MASTERS."/{$id}", ['front_axles' => [2], 'rear_axles' => [1], 'spare_tires' => 0], $headers)->assertStatus(201);
        $after = $this->getJson("/api/v1/app/vehicles/{$vehicle->id}/wheel-configuration", $headers)->assertOk();
        $after->assertJsonPath('data.mapping.version.config_code', '1.2')
            ->assertJsonPath('data.mapping.version.version_number', 1)
            ->assertJsonPath('data.mapping.is_current_version', false)
            ->assertJsonPath('data.mapping.master.config_code', '2.1')
            ->assertJsonPath('data.history.0.status', 'ACTIVE');
    }

    public function test_list_can_be_limited_to_configurations_compatible_with_a_vehicle(): void
    {
        [$tenant, $branch, $category, $headers] = $this->scenario();
        $car = $this->car($tenant, $branch, $category);
        $noData = $this->makeVehicle($tenant, $branch, $category);
        $this->postJson(self::MASTERS, ['vehicle_type' => 'PASSENGER_CAR', 'front_axles' => [1], 'rear_axles' => [2], 'spare_tires' => 0], $headers)->assertStatus(201); // 2 / 6 ✓
        $this->postJson(self::MASTERS, ['vehicle_type' => 'PASSENGER_CAR', 'front_axles' => [1], 'rear_axles' => [1], 'spare_tires' => 0], $headers)->assertStatus(201); // 2 / 4 ✗
        $this->postJson(self::MASTERS, ['vehicle_type' => 'BUS', 'front_axles' => [1], 'rear_axles' => [2], 'spare_tires' => 0], $headers)->assertStatus(201); // other type ✗

        $codes = array_column($this->getJson(self::MASTERS."?compatible_vehicle_id={$car->id}", $headers)->assertOk()->json('data'), 'config_code');
        $this->assertSame(['1.2'], $codes);
        $this->assertSame([], $this->getJson(self::MASTERS."?compatible_vehicle_id={$noData->id}", $headers)->assertOk()->json('data'));
    }

    public function test_authorization_and_tenant_isolation(): void
    {
        [$tenant, $branch, $category, $headers] = $this->scenario();
        $vehicle = $this->car($tenant, $branch, $category);
        [, $noTireToken] = $this->makeTenantUser($tenant, ['vehicle.view']);
        $this->getJson("/api/v1/app/vehicles/{$vehicle->id}/wheel-configuration", $this->authHeaders($noTireToken))->assertForbidden();

        [, , , $otherHeaders] = $this->scenario();
        $this->getJson("/api/v1/app/vehicles/{$vehicle->id}/wheel-configuration", $otherHeaders)->assertNotFound();
        $this->getJson(self::MASTERS."?compatible_vehicle_id={$vehicle->id}", $otherHeaders)->assertNotFound();
    }
}
