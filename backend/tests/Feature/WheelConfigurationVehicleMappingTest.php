<?php

namespace Tests\Feature;

use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInstallation;
use App\Domain\Tire\Models\VehicleWheelConfigurationMapping;
use App\Domain\Tire\Services\VehicleTypeClassifier;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Vehicle Mapping of wheel configuration masters: backend eligibility (vehicle type + total axles +
 * total wheels), atomic add/remove/update-version saves, mapping history, one active mapping per
 * vehicle, version pinning and the installed-tire safety rule.
 */
class WheelConfigurationVehicleMappingTest extends TestCase
{
    private const MASTERS = '/api/v1/app/wheel-configuration-masters';

    private const PERMISSIONS = ['tire.view', 'tire.manage', 'tire.install', 'tire.remove', 'wheel_configuration.map_vehicle'];

    private function scenario(array $permissions = self::PERMISSIONS, ?array $dataScopes = null): array
    {
        $tenant = $this->makeTenant(['code' => 'WCVM-'.Str::random(4)]);
        foreach (['VEHICLE', 'INVENTORY', 'TIRE'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        [, $token] = $this->makeTenantUser($tenant, $permissions, $dataScopes);

        return [$tenant, $branch, $this->makeVehicleCategory(), $this->authHeaders($token)];
    }

    private function vehicle($tenant, $branch, $category, ?string $type, ?int $axles, ?int $wheels, string $reg): Vehicle
    {
        return $this->makeVehicle($tenant, $branch, $category, ['vehicle_type' => $type, 'axle_count' => $axles, 'wheel_count' => $wheels, 'registration_number' => $reg]);
    }

    /** Passenger Car 1.2, no spare: 2 axles, 6 wheels. */
    private function master(array $headers, array $front = [1], array $rear = [2], int $spare = 0, string $type = 'PASSENGER_CAR', ?string $truck = null): string
    {
        return $this->postJson(self::MASTERS, array_filter(['vehicle_type' => $type, 'truck_configuration_type' => $truck, 'front_axles' => $front, 'rear_axles' => $rear, 'spare_tires' => $spare], fn ($v) => $v !== null), $headers)
            ->assertStatus(201)->json('data.master.id');
    }

    private function save(array $headers, string $masterId, array $add = [], array $remove = [], array $update = [])
    {
        return $this->putJson(self::MASTERS."/{$masterId}/vehicle-mappings", ['add_vehicle_ids' => $add, 'remove_vehicle_ids' => $remove, 'update_vehicle_ids' => $update], $headers);
    }

    private function page(array $headers, string $masterId): array
    {
        return $this->getJson(self::MASTERS."/{$masterId}/vehicle-mappings", $headers)->assertOk()->json('data');
    }

    private function install($tenant, Vehicle $vehicle, array $headers, string $position, string $serial): Tire
    {
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE']);
        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => $serial, 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => $position, 'odometer' => 100], $headers)->assertStatus(201);

        return $tire;
    }

    public function test_vehicle_type_classifier_resolves_legacy_text(): void
    {
        $this->assertSame('PASSENGER_CAR', VehicleTypeClassifier::resolve('Car'));
        $this->assertSame('PASSENGER_CAR', VehicleTypeClassifier::resolve('Passenger Car'));
        $this->assertSame('TRUCK', VehicleTypeClassifier::resolve(' truck '));
        $this->assertSame('HEAVY_EQUIPMENT', VehicleTypeClassifier::resolve('Heavy Equipment'));
        $this->assertSame('BUS', VehicleTypeClassifier::resolve('BUS'));
        $this->assertNull(VehicleTypeClassifier::resolve('Motorcycle'));
        $this->assertNull(VehicleTypeClassifier::resolve(null));
    }

    public function test_only_compatible_unmapped_vehicles_are_eligible(): void
    {
        [$tenant, $branch, $category, $headers] = $this->scenario();
        $id = $this->master($headers);
        $otherId = $this->master($headers, [2], [1]); // 2.1 → also 2 axles / 6 wheels

        $a = $this->vehicle($tenant, $branch, $category, 'Car', 2, 6, 'A-CAR');
        $b = $this->vehicle($tenant, $branch, $category, 'PASSENGER_CAR', 2, 6, 'B-CAR');
        $this->vehicle($tenant, $branch, $category, 'Bus', 2, 6, 'C-BUS');
        $this->vehicle($tenant, $branch, $category, 'Truck', 2, 6, 'D-TRUCK');
        $this->vehicle($tenant, $branch, $category, 'Car', 2, 4, 'E-CAR-4W');
        $this->vehicle($tenant, $branch, $category, 'Car', 3, 6, 'F-CAR-3AX');
        $this->vehicle($tenant, $branch, $category, 'Car', null, null, 'G-CAR-NODATA');
        $this->vehicle($tenant, $branch, $category, 'Car', 2, 6, 'H-DISPOSED')->update(['status' => 'DISPOSED']);
        $mappedElsewhere = $this->vehicle($tenant, $branch, $category, 'Car', 2, 6, 'I-CAR-OTHER');
        $this->save($headers, $otherId, [$mappedElsewhere->id])->assertOk();

        $page = $this->page($headers, $id);
        $this->assertSame(['A-CAR', 'B-CAR'], array_column($page['available_vehicles'], 'registration_number'));
        $this->assertSame([], $page['mapped_vehicles']);
        $this->assertSame(['incomplete_vehicle_data' => 1, 'mapped_to_other_configuration' => 1], $page['excluded']);
        $this->assertSame(['PASSENGER_CAR', 2, 6, 0, '1.2'], [$page['configuration']['vehicle_type'], $page['configuration']['total_axles'], $page['configuration']['total_wheels'], $page['configuration']['spare_tires'], $page['configuration']['config_code']]);
        $this->assertSame('PASSENGER_CAR', $page['available_vehicles'][0]['vehicle_type']);
    }

    public function test_add_and_remove_are_persisted_with_history(): void
    {
        [$tenant, $branch, $category, $headers] = $this->scenario();
        $id = $this->master($headers);
        $a = $this->vehicle($tenant, $branch, $category, 'Car', 2, 6, 'A-CAR');

        $res = $this->save($headers, $id, [$a->id])->assertOk();
        $res->assertJsonPath('data.summary', ['added' => 1, 'removed' => 0, 'updated' => 0]);
        $this->assertSame(['A-CAR'], array_column($res->json('data.mapped_vehicles'), 'registration_number'));
        $this->assertSame([], $res->json('data.available_vehicles'));
        $this->assertSame(1, $res->json('data.mapped_vehicles.0.version_number'));

        $page = $this->page($headers, $id);
        $this->assertSame(['A-CAR'], array_column($page['mapped_vehicles'], 'registration_number'));
        $active = $a->fresh()->activeWheelConfigurationMapping;
        $this->assertSame($id, $active->wheel_configuration_master_id);

        $this->save($headers, $id, [], [$a->id])->assertOk()->assertJsonPath('data.summary.removed', 1);
        $page = $this->page($headers, $id);
        $this->assertSame([], $page['mapped_vehicles']);
        $this->assertSame(['A-CAR'], array_column($page['available_vehicles'], 'registration_number'));
        $this->assertNull($a->fresh()->activeWheelConfigurationMapping);

        $history = VehicleWheelConfigurationMapping::query()->withoutGlobalScopes()->where('vehicle_id', $a->id)->get();
        $this->assertCount(1, $history);
        $this->assertSame(['ENDED', 'UNMAPPED'], [$history[0]->status, $history[0]->end_reason]);
        $this->assertNotNull($history[0]->ended_at);
        $this->assertNotNull($history[0]->ended_by);
        $this->assertNotNull($history[0]->mapped_by);
    }

    public function test_save_is_atomic_and_rejects_incompatible_vehicles(): void
    {
        [$tenant, $branch, $category, $headers] = $this->scenario();
        $id = $this->master($headers);
        $a = $this->vehicle($tenant, $branch, $category, 'Car', 2, 6, 'A-CAR');
        $bus = $this->vehicle($tenant, $branch, $category, 'Bus', 2, 6, 'C-BUS');

        $res = $this->save($headers, $id, [$a->id, $bus->id])->assertStatus(422);
        $this->assertStringContainsString('C-BUS', json_encode($res->json('errors.vehicles')));
        $this->assertSame(0, VehicleWheelConfigurationMapping::query()->withoutGlobalScopes()->where('wheel_configuration_master_id', $id)->count());
    }

    public function test_a_vehicle_has_at_most_one_active_configuration(): void
    {
        [$tenant, $branch, $category, $headers] = $this->scenario();
        $first = $this->master($headers);
        $second = $this->master($headers, [2], [1]);
        $a = $this->vehicle($tenant, $branch, $category, 'Car', 2, 6, 'A-CAR');
        $this->save($headers, $first, [$a->id])->assertOk();

        $this->assertSame([], $this->page($headers, $second)['available_vehicles']);
        $res = $this->save($headers, $second, [$a->id])->assertStatus(422);
        $this->assertStringContainsString('mapped to configuration 1.2', json_encode($res->json('errors.vehicles')));
        $this->save($headers, $first, [$a->id])->assertStatus(422);
        $this->assertSame(1, VehicleWheelConfigurationMapping::query()->withoutGlobalScopes()->where('vehicle_id', $a->id)->where('status', 'ACTIVE')->count());
    }

    public function test_editing_the_master_keeps_mapped_vehicles_on_their_version_until_updated(): void
    {
        [$tenant, $branch, $category, $headers] = $this->scenario();
        $id = $this->master($headers);
        $a = $this->vehicle($tenant, $branch, $category, 'Car', 2, 6, 'A-CAR');
        $this->save($headers, $id, [$a->id])->assertOk();
        $v1 = $a->fresh()->activeWheelConfigurationMapping->wheel_configuration_version_id;

        $this->postJson(self::MASTERS.'/preview', ['wheel_configuration_master_id' => $id, 'front_axles' => [2], 'rear_axles' => [1], 'spare_tires' => 0], $headers)
            ->assertOk()->assertJsonPath('data.mapped_vehicle_count', 1);
        $this->putJson(self::MASTERS."/{$id}", ['front_axles' => [2], 'rear_axles' => [1], 'spare_tires' => 0], $headers)->assertStatus(201);

        $page = $this->page($headers, $id);
        $this->assertSame('2.1', $page['configuration']['config_code']);
        $this->assertSame([1, false, '1.2'], [$page['mapped_vehicles'][0]['version_number'], $page['mapped_vehicles'][0]['is_current_version'], $page['mapped_vehicles'][0]['mapped_config_code']]);
        $this->assertSame($v1, $a->fresh()->activeWheelConfigurationMapping->wheel_configuration_version_id);

        $this->save($headers, $id, [], [], [$a->id])->assertOk()->assertJsonPath('data.summary.updated', 1)
            ->assertJsonPath('data.mapped_vehicles.0.version_number', 2)->assertJsonPath('data.mapped_vehicles.0.is_current_version', true);
        $rows = VehicleWheelConfigurationMapping::query()->withoutGlobalScopes()->where('vehicle_id', $a->id)->orderBy('created_at')->get();
        $this->assertSame(['ENDED', 'ACTIVE'], $rows->pluck('status')->all());
        $this->assertSame('VERSION_UPDATED', $rows[0]->end_reason);
        $this->assertSame($rows[0]->id, $rows[1]->previous_mapping_id);
        $this->save($headers, $id, [], [], [$a->id])->assertStatus(422);
    }

    public function test_remapping_is_blocked_by_an_active_tire_on_a_removed_position(): void
    {
        [$tenant, $branch, $category, $headers] = $this->scenario();
        $id = $this->master($headers); // 1.2: 1FL1 1FR1 1RL1 1RL2 1RR1 1RR2
        $a = $this->vehicle($tenant, $branch, $category, 'Car', 2, 6, 'B 1234 WCV');
        $this->save($headers, $id, [$a->id])->assertOk();
        $tire = $this->install($tenant, $a, $headers, '1RL2', 'SN-00123');
        $this->putJson(self::MASTERS."/{$id}", ['front_axles' => [2], 'rear_axles' => [1], 'spare_tires' => 0], $headers)->assertStatus(201); // 2.1 has no 1RL2

        $res = $this->save($headers, $id, [], [], [$a->id])->assertStatus(422);
        $this->assertStringContainsString('Vehicle cannot be remapped because active tires are installed on positions removed by the target configuration', $res->json('message'));
        $this->assertStringContainsString('B 1234 WCV 1RL2 — Tire SN-00123', $res->json('message'));
        $res->assertJsonPath('blockers.0.position_code', '1RL2')->assertJsonPath('blockers.0.tire_id', $tire->id)->assertJsonPath('blockers.0.vehicle_id', $a->id);

        // Nothing changed: still on version 1, the tire untouched.
        $this->assertSame(1, $this->page($headers, $id)['mapped_vehicles'][0]['version_number']);
        $this->assertSame(['INSTALLED', '1RL2'], [$tire->fresh()->current_status, $tire->fresh()->current_position]);

        // Re-adding an unmapped vehicle into a configuration without its occupied position is blocked too.
        $this->save($headers, $id, [], [$a->id])->assertOk();
        $this->save($headers, $id, [$a->id])->assertStatus(422)->assertJsonPath('blockers.0.position_code', '1RL2');
    }

    public function test_remapping_keeps_installations_on_positions_that_remain(): void
    {
        [$tenant, $branch, $category, $headers] = $this->scenario();
        $id = $this->master($headers); // 1.2
        $a = $this->vehicle($tenant, $branch, $category, 'Car', 2, 6, 'A-CAR');
        $this->save($headers, $id, [$a->id])->assertOk();
        $tire = $this->install($tenant, $a, $headers, '1FL1', 'SN-KEEP');
        $installation = TireInstallation::query()->withoutGlobalScopes()->where('tire_id', $tire->id)->sole();
        $this->putJson(self::MASTERS."/{$id}", ['front_axles' => [2], 'rear_axles' => [1], 'spare_tires' => 0], $headers)->assertStatus(201); // 2.1 keeps 1FL1

        $this->save($headers, $id, [], [], [$a->id])->assertOk();
        $after = TireInstallation::query()->withoutGlobalScopes()->where('tire_id', $tire->id)->get();
        $this->assertCount(1, $after);
        $this->assertSame([$installation->id, '1FL1', null], [$after[0]->id, $after[0]->wheel_position, $after[0]->removed_at]);
        $this->assertSame(['INSTALLED', '1FL1'], [$tire->fresh()->current_status, $tire->fresh()->current_position]);
    }

    public function test_permissions_and_tenant_isolation(): void
    {
        [$tenant, $branch, $category, $headers] = $this->scenario();
        $id = $this->master($headers);
        $a = $this->vehicle($tenant, $branch, $category, 'Car', 2, 6, 'A-CAR');

        [, $viewToken] = $this->makeTenantUser($tenant, ['tire.view']);
        $viewOnly = $this->authHeaders($viewToken);
        $this->getJson(self::MASTERS."/{$id}/vehicle-mappings", $viewOnly)->assertOk();
        $this->save($viewOnly, $id, [$a->id])->assertStatus(403);

        [$otherTenant, $otherBranch, $otherCategory, $otherHeaders] = $this->scenario();
        $this->getJson(self::MASTERS."/{$id}/vehicle-mappings", $otherHeaders)->assertNotFound();
        $this->save($otherHeaders, $id, [$a->id])->assertNotFound();
        $foreign = $this->vehicle($otherTenant, $otherBranch, $otherCategory, 'Car', 2, 6, 'X-FOREIGN');
        $this->save($headers, $id, [$foreign->id])->assertStatus(422)->assertJsonValidationErrors('vehicles');
        $this->assertSame(0, VehicleWheelConfigurationMapping::query()->withoutGlobalScopes()->count());
    }

    public function test_vehicles_outside_the_users_data_scope_are_neither_listed_nor_mappable(): void
    {
        $tenant = $this->makeTenant(['code' => 'WCVS-'.Str::random(4)]);
        foreach (['VEHICLE', 'INVENTORY', 'TIRE'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $mine = $this->makeBranch($tenant);
        $theirs = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        [, $token] = $this->makeTenantUser($tenant, self::PERMISSIONS, ['BRANCH' => $mine->id]);
        $headers = $this->authHeaders($token);
        $id = $this->master($headers);
        $this->vehicle($tenant, $mine, $category, 'Car', 2, 6, 'MINE');
        $outside = $this->vehicle($tenant, $theirs, $category, 'Car', 2, 6, 'OUTSIDE');

        $this->assertSame(['MINE'], array_column($this->page($headers, $id)['available_vehicles'], 'registration_number'));
        $this->save($headers, $id, [$outside->id])->assertStatus(403);
    }
}
