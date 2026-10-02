<?php

namespace Tests\Feature;

use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\WheelConfigurationMaster;
use App\Domain\Tire\Models\WheelConfigurationVersion;
use App\Domain\Tire\Models\WheelConfigurationVersionPosition;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Wheel Configuration master/template: Validate → Generate Config Code → Generate Position List →
 * Save Configuration Version. No vehicle is involved (vehicle assignment is a future feature).
 */
class WheelConfigurationMasterTest extends TestCase
{
    private const URL = '/api/v1/app/wheel-configuration-masters';

    private function scenario(array $permissions = ['tire.view', 'tire.manage']): array
    {
        $tenant = $this->makeTenant(['code' => 'WCM-'.Str::random(4)]);
        $this->grantModule($tenant, 'TIRE');
        [, $token] = $this->makeTenantUser($tenant, $permissions);

        return [$tenant, $this->authHeaders($token)];
    }

    private function payload(array $front, array $rear, int $spare, string $type = 'PASSENGER_CAR', ?string $truckType = null): array
    {
        return array_filter([
            'vehicle_type' => $type,
            'truck_configuration_type' => $truckType,
            'front_axles' => $front,
            'rear_axles' => $rear,
            'spare_tires' => $spare,
        ], fn ($v) => $v !== null);
    }

    private function codes(string $versionId): array
    {
        return WheelConfigurationVersionPosition::query()->withoutGlobalScopes()->where('wheel_configuration_version_id', $versionId)
            ->orderBy('sequence')->pluck('position_code')->all();
    }

    public function test_passenger_car_configuration_master_is_created_without_any_vehicle(): void
    {
        [$tenant, $headers] = $this->scenario();

        $res = $this->postJson(self::URL, $this->payload([2, 2], [2, 2, 2], 1), $headers)->assertStatus(201);
        $res->assertJsonPath('data.master.vehicle_type', 'PASSENGER_CAR')
            ->assertJsonPath('data.master.truck_configuration_type', null)
            ->assertJsonPath('data.master.config_code', '22.222')
            ->assertJsonPath('data.master.status', 'ACTIVE')
            ->assertJsonPath('data.version.version_number', 1)
            ->assertJsonPath('data.version.status', 'ACTIVE')
            ->assertJsonPath('data.version.total_axles', 5)
            ->assertJsonPath('data.version.total_wheels', 21)
            ->assertJsonPath('data.version.position_diff.removed', []);

        $master = WheelConfigurationMaster::query()->withoutGlobalScopes()->findOrFail($res->json('data.master.id'));
        $this->assertSame($tenant->id, $master->tenant_id);
        $this->assertSame($res->json('data.version.id'), $master->current_version_id);
        $codes = $this->codes($master->current_version_id);
        $this->assertCount(21, $codes);
        $this->assertSame(['1FL1', '1FL2', '1FR1', '1FR2', '2FL1', '2FL2', '2FR1', '2FR2', '1RL1', '1RL2'], array_slice($codes, 0, 10));
        $this->assertSame('S1', end($codes));
        $this->assertSame($codes, $res->json('data.version.position_diff.added'));

        $position = WheelConfigurationVersionPosition::query()->withoutGlobalScopes()->where('wheel_configuration_version_id', $master->current_version_id)->where('position_code', '2RL2')->sole();
        $this->assertSame(['REAR', 2, 4, 'L', 2, 'Rear Axle 2 Left Wheel 2'], [$position->position_group, $position->axle_in_group, $position->axle_number, $position->side, $position->wheel_index, $position->label]);
    }

    public function test_no_vehicle_columns_exist_on_the_configuration_tables(): void
    {
        foreach (['wheel_configuration_masters', 'wheel_configuration_versions', 'wheel_configuration_version_positions'] as $table) {
            foreach (['vehicle_id', 'vehicle_category_id'] as $column) {
                $this->assertFalse(Schema::hasColumn($table, $column), "{$table}.{$column} must not exist");
            }
        }
    }

    public function test_the_three_truck_configuration_types_are_distinct_masters(): void
    {
        [, $headers] = $this->scenario();

        foreach (['NON_TRAILER' => '22.222', 'TRAILER' => '+22.222', 'SEMI_TRAILER' => '-22.222'] as $type => $code) {
            $this->postJson(self::URL, $this->payload([2, 2], [2, 2, 2], 0, 'TRUCK', $type) + ['config_code' => $code], $headers)->assertStatus(201)
                ->assertJsonPath('data.master.vehicle_type', 'TRUCK')
                ->assertJsonPath('data.master.truck_configuration_type', $type)
                ->assertJsonPath('data.master.config_code', $code)
                ->assertJsonPath('data.version.total_wheels', 20);
        }

        $list = $this->getJson(self::URL.'?vehicle_type=TRUCK', $headers)->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['22.222', '+22.222', '-22.222'], array_column($list, 'config_code'));
        $this->assertSame([5, 5, 5], array_column(array_column($list, 'current_version'), 'total_axles'));
    }

    public function test_the_same_identity_cannot_be_created_twice(): void
    {
        [, $headers] = $this->scenario();
        $this->postJson(self::URL, $this->payload([2, 2], [2, 2, 2], 0, 'TRUCK', 'TRAILER'), $headers)->assertStatus(201);

        $this->postJson(self::URL, $this->payload([2, 2], [2, 2, 2], 2, 'TRUCK', 'TRAILER'), $headers)->assertStatus(422)->assertJsonValidationErrors('config_code');
        // Same axles under another vehicle type is a different configuration.
        $this->postJson(self::URL, $this->payload([2, 2], [2, 2, 2], 0, 'BUS'), $headers)->assertStatus(201);
        $this->postJson(self::URL.'/preview', $this->payload([2, 2], [2, 2, 2], 0, 'TRUCK', 'TRAILER'), $headers)->assertOk()
            ->assertJsonPath('data.duplicate.config_code', '+22.222');
    }

    public function test_editing_creates_a_new_version_and_stores_the_position_diff(): void
    {
        [, $headers] = $this->scenario();
        $created = $this->postJson(self::URL, $this->payload([1], [2, 2], 1, 'TRUCK', 'NON_TRAILER'), $headers)->assertStatus(201);
        $id = $created->json('data.master.id');
        $v1 = $created->json('data.version.id');

        $res = $this->putJson(self::URL."/{$id}", $this->payload([1], [2, 1], 0, 'TRUCK', 'NON_TRAILER') + ['config_code' => '1.21'], $headers)->assertStatus(201);
        $res->assertJsonPath('data.created', true)
            ->assertJsonPath('data.master.config_code', '1.21')
            ->assertJsonPath('data.master.truck_configuration_type', 'NON_TRAILER')
            ->assertJsonPath('data.version.version_number', 2)
            ->assertJsonPath('data.version.total_axles', 3)
            ->assertJsonPath('data.version.total_wheels', 8);
        $this->assertEqualsCanonicalizing(['1FL1', '1FR1', '1RL1', '1RL2', '1RR1', '1RR2', '2RL1', '2RR1'], $res->json('data.version.position_diff.unchanged'));
        $this->assertSame([], $res->json('data.version.position_diff.added'));
        $this->assertEqualsCanonicalizing(['2RL2', '2RR2', 'S1'], $res->json('data.version.position_diff.removed'));

        // The previous version is INACTIVE and still holds its full position list (history).
        $old = WheelConfigurationVersion::query()->withoutGlobalScopes()->findOrFail($v1);
        $this->assertSame('INACTIVE', $old->status);
        $this->assertNotNull($old->deactivated_at);
        $this->assertSame('1.22', $old->config_code);
        $this->assertContains('2RL2', $this->codes($v1));
        $this->assertContains('S1', $this->codes($v1));
        $this->assertNotContains('2RL2', $this->codes($res->json('data.version.id')));

        $show = $this->getJson(self::URL."/{$id}", $headers)->assertOk();
        $this->assertSame([2, 1], array_column($show->json('data.versions'), 'version_number'));
        $this->assertSame(['ACTIVE', 'INACTIVE'], array_column($show->json('data.versions'), 'status'));
        $this->assertSame(['1.21', '1.22'], array_column($show->json('data.versions'), 'config_code'));
        $this->assertCount(11, $show->json('data.versions.1.positions'));
    }

    public function test_an_unchanged_edit_creates_no_version(): void
    {
        [, $headers] = $this->scenario();
        $id = $this->postJson(self::URL, $this->payload([1], [2], 1), $headers)->assertStatus(201)->json('data.master.id');

        $this->putJson(self::URL."/{$id}", $this->payload([1], [2], 1), $headers)->assertOk()->assertJsonPath('data.created', false)->assertJsonPath('data.version.version_number', 1);
        $this->postJson(self::URL.'/preview', $this->payload([1], [2], 1) + ['wheel_configuration_master_id' => $id], $headers)->assertOk()->assertJsonPath('data.changed', false);
        $this->assertSame(1, WheelConfigurationVersion::query()->withoutGlobalScopes()->where('wheel_configuration_master_id', $id)->count());
    }

    public function test_vehicle_type_and_truck_type_of_a_master_cannot_change(): void
    {
        [, $headers] = $this->scenario();
        $id = $this->postJson(self::URL, $this->payload([2], [2], 0, 'TRUCK', 'TRAILER'), $headers)->assertStatus(201)->json('data.master.id');

        $this->putJson(self::URL."/{$id}", $this->payload([2], [2, 2], 0, 'TRUCK', 'SEMI_TRAILER'), $headers)->assertStatus(422)->assertJsonValidationErrors('truck_configuration_type');
        $this->putJson(self::URL."/{$id}", $this->payload([2], [2, 2], 0, 'BUS'), $headers)->assertStatus(422)->assertJsonValidationErrors('vehicle_type');
    }

    public function test_editing_into_another_masters_identity_is_rejected(): void
    {
        [, $headers] = $this->scenario();
        $this->postJson(self::URL, $this->payload([1], [2], 0), $headers)->assertStatus(201);
        $id = $this->postJson(self::URL, $this->payload([1], [1], 0), $headers)->assertStatus(201)->json('data.master.id');

        $this->putJson(self::URL."/{$id}", $this->payload([1], [2], 0), $headers)->assertStatus(422)->assertJsonValidationErrors('config_code');
        $this->assertSame('1.1', WheelConfigurationMaster::query()->withoutGlobalScopes()->findOrFail($id)->config_code);
    }

    public function test_preview_returns_generated_positions_and_diff_without_writing(): void
    {
        [$tenant, $headers] = $this->scenario();
        $preview = $this->postJson(self::URL.'/preview', $this->payload([1], [2, 1], 0, 'TRUCK', 'NON_TRAILER'), $headers)->assertOk();
        $preview->assertJsonPath('data.configuration.config_code', '1.21')
            ->assertJsonPath('data.configuration.total_wheels', 8)
            ->assertJsonPath('data.diff', null)
            ->assertJsonPath('data.changed', true)
            ->assertJsonPath('data.duplicate', null);
        $this->assertSame(['1FL1', '1FR1', '1RL1', '1RL2', '1RR1', '1RR2', '2RL1', '2RR1'], array_column($preview->json('data.configuration.positions'), 'position_code'));
        $this->assertSame(0, WheelConfigurationMaster::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        $id = $this->postJson(self::URL, $this->payload([1], [2, 2], 1, 'TRUCK', 'NON_TRAILER'), $headers)->json('data.master.id');
        $edit = $this->postJson(self::URL.'/preview', ['front_axles' => [1], 'rear_axles' => [2, 1], 'spare_tires' => 0, 'wheel_configuration_master_id' => $id], $headers)->assertOk();
        $edit->assertJsonPath('data.current_version.config_code', '1.22');
        $this->assertEqualsCanonicalizing(['2RL2', '2RR2', 'S1'], $edit->json('data.diff.removed'));
        $this->assertArrayNotHasKey('blockers', $edit->json('data'));
    }

    public function test_config_code_is_generated_by_the_server(): void
    {
        [$tenant, $headers] = $this->scenario();
        $payload = $this->payload([2, 2], [2, 2, 2], 0, 'TRUCK', 'TRAILER');

        $this->postJson(self::URL, $payload + ['config_code' => '22.222'], $headers)->assertStatus(422)->assertJsonValidationErrors('config_code');
        $this->postJson(self::URL, $payload + ['config_code' => '-22.222'], $headers)->assertStatus(422)->assertJsonValidationErrors('config_code');
        $this->assertSame(0, WheelConfigurationMaster::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->postJson(self::URL, $payload, $headers)->assertStatus(201)->assertJsonPath('data.master.config_code', '+22.222');
    }

    public function test_invalid_configurations_are_rejected(): void
    {
        [$tenant, $headers] = $this->scenario();

        $this->postJson(self::URL, $this->payload([2], [2], 0, 'TRUCK'), $headers)->assertStatus(422)->assertJsonValidationErrors('truck_configuration_type');
        $this->postJson(self::URL, $this->payload([2], [2], 0, 'BUS', 'TRAILER'), $headers)->assertStatus(422)->assertJsonValidationErrors('truck_configuration_type');
        $this->postJson(self::URL, $this->payload([], [2], 0), $headers)->assertStatus(422)->assertJsonValidationErrors('front_axles');
        $this->postJson(self::URL, $this->payload([5], [2], 0), $headers)->assertStatus(422)->assertJsonValidationErrors('front_axles.0');
        $this->postJson(self::URL, $this->payload([1], [1], 5), $headers)->assertStatus(422)->assertJsonValidationErrors('spare_tires');
        $this->assertSame(0, WheelConfigurationMaster::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_save_never_checks_installed_tires(): void
    {
        [$tenant, $headers] = $this->scenario(['tire.view', 'tire.manage', 'tire.install']);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'INVENTORY');
        $vehicle = $this->makeVehicle($tenant, $this->makeBranch($tenant), $this->makeVehicleCategory());
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE']);
        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'SN-WCM-1', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => '2RL2', 'odometer' => 100], $headers)->assertStatus(201);

        $id = $this->postJson(self::URL, $this->payload([1], [2, 2], 0), $headers)->assertStatus(201)->json('data.master.id');
        $this->putJson(self::URL."/{$id}", $this->payload([1], [2, 1], 0), $headers)->assertStatus(201);
        $this->assertSame('INSTALLED', $tire->fresh()->current_status);
        $this->assertSame('2RL2', $tire->fresh()->current_position);
    }

    public function test_tenant_isolation(): void
    {
        [$tenantA, $headersA] = $this->scenario();
        [, $headersB] = $this->scenario();
        $id = $this->postJson(self::URL, $this->payload([1], [2], 0), $headersA)->assertStatus(201)->json('data.master.id');

        // Tenant B can create the same configuration independently and cannot see or edit A's.
        $this->postJson(self::URL, $this->payload([1], [2], 0), $headersB)->assertStatus(201);
        $this->assertCount(1, $this->getJson(self::URL, $headersB)->json('data'));
        $this->getJson(self::URL."/{$id}", $headersB)->assertNotFound();
        $this->putJson(self::URL."/{$id}", $this->payload([1], [1], 0), $headersB)->assertNotFound();
        $this->postJson(self::URL.'/preview', $this->payload([1], [1], 0) + ['wheel_configuration_master_id' => $id], $headersB)->assertNotFound();
        $this->assertSame('1.2', WheelConfigurationMaster::query()->withoutGlobalScopes()->findOrFail($id)->config_code);
        $this->assertSame($tenantA->id, WheelConfigurationMaster::query()->withoutGlobalScopes()->findOrFail($id)->tenant_id);
    }

    public function test_permissions_are_enforced(): void
    {
        [, $headers] = $this->scenario(['tire.view']);

        $this->postJson(self::URL, $this->payload([1], [1], 0), $headers)->assertStatus(403);
        $this->postJson(self::URL.'/preview', $this->payload([1], [1], 0), $headers)->assertStatus(403);
        $this->getJson(self::URL, $headers)->assertOk();
    }
}
