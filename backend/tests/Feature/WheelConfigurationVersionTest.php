<?php

namespace Tests\Feature;

use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireInstallation;
use App\Domain\Tire\Models\WheelConfiguration;
use App\Domain\Tire\Models\WheelConfigurationVersion;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Production Save of "New Wheels Configuration": versioning + position-set diffing
 * (UNCHANGED / ADDED / REMOVED), installed-tire blocking, retire-not-delete, server-generated
 * Config Code and explicit Truck Configuration Type.
 */
class WheelConfigurationVersionTest extends TestCase
{
    private const URL = '/api/v1/app/wheel-configuration-versions';

    private function scenario(array $permissions = ['tire.view', 'tire.manage', 'tire.install', 'tire.remove', 'tire.rotate']): array
    {
        $tenant = $this->makeTenant(['code' => 'WCV-'.Str::random(4)]);
        foreach (['VEHICLE', 'INVENTORY', 'TIRE'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'B 1234 WCV']);
        [, $token] = $this->makeTenantUser($tenant, $permissions);

        return [$tenant, $category, $vehicle, $this->authHeaders($token)];
    }

    private function payload(string $categoryId, array $front, array $rear, int $spare, string $type = 'PASSENGER_CAR', ?string $truckType = null): array
    {
        return array_filter([
            'vehicle_category_id' => $categoryId,
            'vehicle_type' => $type,
            'truck_configuration_type' => $truckType,
            'front_axles' => $front,
            'rear_axles' => $rear,
            'spare_tires' => $spare,
        ], fn ($v) => $v !== null);
    }

    private function installTire($tenant, $vehicle, array $headers, string $position, string $serial): Tire
    {
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE']);
        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => $serial, 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tire->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => $position, 'odometer' => 1000], $headers)->assertStatus(201);

        return $tire;
    }

    /** @return array<string, WheelConfiguration> */
    private function positions(string $tenantId, string $categoryId): array
    {
        return WheelConfiguration::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('vehicle_category_id', $categoryId)
            ->get()->keyBy('position_code')->all();
    }

    public function test_first_save_creates_an_active_version_and_its_positions(): void
    {
        [$tenant, $category, , $headers] = $this->scenario();

        $res = $this->postJson(self::URL, $this->payload($category->id, [1], [2], 1), $headers)->assertStatus(201);
        $res->assertJsonPath('data.created', true)
            ->assertJsonPath('data.version.version_number', 1)
            ->assertJsonPath('data.version.status', 'ACTIVE')
            ->assertJsonPath('data.version.config_code', '1.2')
            ->assertJsonPath('data.version.vehicle_type', 'PASSENGER_CAR')
            ->assertJsonPath('data.version.truck_configuration_type', null)
            ->assertJsonPath('data.version.total_axles', 2)
            ->assertJsonPath('data.version.total_wheels', 7)
            ->assertJsonPath('data.diff.unchanged', [])
            ->assertJsonPath('data.diff.removed', []);
        $this->assertEqualsCanonicalizing(['1FL1', '1FR1', '1RL1', '1RL2', '1RR1', '1RR2', 'S1'], $res->json('data.diff.added'));

        $positions = $this->positions($tenant->id, $category->id);
        $this->assertCount(7, $positions);
        $this->assertSame('ACTIVE', $positions['1RL2']->status);
        $this->assertSame('REAR', $positions['1RL2']->position_group);
        $this->assertSame('L', $positions['1RL2']->side);
        $this->assertSame(2, $positions['1RL2']->wheel_index);
        $this->assertSame('Rear Axle 1 Left Wheel 2', $positions['1RL2']->label);
        $this->assertSame('SPARE', $positions['S1']->position_group);
        $this->assertSame($res->json('data.version.id'), $positions['S1']->introduced_in_version_id);
    }

    public function test_saving_the_identical_configuration_is_idempotent(): void
    {
        [$tenant, $category, , $headers] = $this->scenario();
        $first = $this->postJson(self::URL, $this->payload($category->id, [1], [2], 1), $headers)->assertStatus(201);

        $this->postJson(self::URL, $this->payload($category->id, [1], [2], 1), $headers)->assertOk()
            ->assertJsonPath('data.created', false)
            ->assertJsonPath('data.version.id', $first->json('data.version.id'));
        $this->assertSame(1, WheelConfigurationVersion::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_change_creates_a_new_version_with_unchanged_added_and_removed_positions(): void
    {
        [$tenant, $category, , $headers] = $this->scenario();
        $v1 = $this->postJson(self::URL, $this->payload($category->id, [1], [2], 1), $headers)->assertStatus(201)->json('data.version.id');
        $before = $this->positions($tenant->id, $category->id);

        $res = $this->postJson(self::URL, $this->payload($category->id, [1], [1, 1], 0), $headers)->assertStatus(201);
        $v2 = $res->json('data.version.id');
        $res->assertJsonPath('data.version.version_number', 2)->assertJsonPath('data.version.config_code', '1.11');
        $this->assertEqualsCanonicalizing(['1FL1', '1FR1', '1RL1', '1RR1'], $res->json('data.diff.unchanged'));
        $this->assertEqualsCanonicalizing(['2RL1', '2RR1'], $res->json('data.diff.added'));
        $this->assertEqualsCanonicalizing(['1RL2', '1RR2', 'S1'], $res->json('data.diff.removed'));

        $this->assertSame('SUPERSEDED', WheelConfigurationVersion::query()->withoutGlobalScopes()->find($v1)->status);
        $this->assertNotNull(WheelConfigurationVersion::query()->withoutGlobalScopes()->find($v1)->superseded_at);
        $this->assertSame('ACTIVE', WheelConfigurationVersion::query()->withoutGlobalScopes()->find($v2)->status);
        $this->assertEqualsCanonicalizing(['unchanged', 'added', 'removed'], array_keys(WheelConfigurationVersion::query()->withoutGlobalScopes()->find($v2)->position_diff));

        $after = $this->positions($tenant->id, $category->id);
        // UNCHANGED: same row, origin kept. REMOVED: same row, RETIRED (never deleted). ADDED: new row.
        $this->assertSame($before['1FL1']->id, $after['1FL1']->id);
        $this->assertSame($v1, $after['1FL1']->introduced_in_version_id);
        $this->assertSame('Rear Axle 1 Left Wheel 1', $after['1RL1']->label);
        foreach (['1RL2', '1RR2', 'S1'] as $code) {
            $this->assertSame($before[$code]->id, $after[$code]->id);
            $this->assertSame('RETIRED', $after[$code]->status);
            $this->assertSame($v2, $after[$code]->retired_in_version_id);
            $this->assertNotNull($after[$code]->retired_at);
        }
        $this->assertSame($v2, $after['2RL1']->introduced_in_version_id);
        $this->assertSame('ACTIVE', $after['2RL1']->status);
        $this->assertCount(9, $after);
    }

    public function test_a_retired_position_code_is_reactivated_on_the_same_row(): void
    {
        [$tenant, $category, , $headers] = $this->scenario();
        $this->postJson(self::URL, $this->payload($category->id, [1], [2], 1), $headers)->assertStatus(201);
        $original = $this->positions($tenant->id, $category->id)['1RL2']->id;
        $this->postJson(self::URL, $this->payload($category->id, [1], [1], 1), $headers)->assertStatus(201);

        $v3 = $this->postJson(self::URL, $this->payload($category->id, [1], [2], 1), $headers)->assertStatus(201);
        $this->assertContains('1RL2', $v3->json('data.diff.added'));
        $row = $this->positions($tenant->id, $category->id)['1RL2'];
        $this->assertSame($original, $row->id);
        $this->assertSame('ACTIVE', $row->status);
        $this->assertNull($row->retired_at);
        $this->assertNull($row->retired_in_version_id);
        $this->assertSame($v3->json('data.version.id'), $row->introduced_in_version_id);
        $this->assertSame(3, $v3->json('data.version.version_number'));
    }

    public function test_installed_tire_on_a_removed_position_blocks_the_change_and_nothing_is_modified(): void
    {
        [$tenant, $category, $vehicle, $headers] = $this->scenario();
        $this->postJson(self::URL, $this->payload($category->id, [1], [2], 1), $headers)->assertStatus(201);
        $tire = $this->installTire($tenant, $vehicle, $headers, '1RL2', 'SN-BLOCK-1');
        $snapshot = collect($this->positions($tenant->id, $category->id))->map(fn ($p) => [$p->status, $p->updated_at?->toIso8601String()])->all();

        $preview = $this->postJson(self::URL.'/preview', $this->payload($category->id, [1], [1], 1), $headers)->assertOk();
        $preview->assertJsonPath('data.can_save', false)->assertJsonPath('data.blockers.0.position_code', '1RL2')
            ->assertJsonPath('data.blockers.0.reason', 'REMOVED')
            ->assertJsonPath('data.blockers.0.tire_serial_number', 'SN-BLOCK-1')
            ->assertJsonPath('data.blockers.0.vehicle_registration_number', 'B 1234 WCV');

        $res = $this->postJson(self::URL, $this->payload($category->id, [1], [1], 1), $headers)->assertStatus(422);
        $this->assertStringContainsString('position 1RL2 has tire SN-BLOCK-1 installed on vehicle B 1234 WCV', $res->json('message'));
        $this->assertStringContainsString('Remove or transfer', $res->json('message'));
        $res->assertJsonPath('blockers.0.tire_id', $tire->id)->assertJsonPath('blockers.0.vehicle_id', $vehicle->id);

        // Atomic: no version, no position change, the tire was not touched.
        $this->assertSame(1, WheelConfigurationVersion::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertSame($snapshot, collect($this->positions($tenant->id, $category->id))->map(fn ($p) => [$p->status, $p->updated_at?->toIso8601String()])->all());
        $tire->refresh();
        $this->assertSame('INSTALLED', $tire->current_status);
        $this->assertSame('1RL2', $tire->current_position);
        $this->assertSame(1, TireInstallation::query()->withoutGlobalScopes()->where('tire_id', $tire->id)->whereNull('removed_at')->count());
    }

    public function test_installed_tire_on_an_unchanged_position_does_not_block(): void
    {
        [$tenant, $category, $vehicle, $headers] = $this->scenario();
        $this->postJson(self::URL, $this->payload($category->id, [1], [2], 1), $headers)->assertStatus(201);
        $tire = $this->installTire($tenant, $vehicle, $headers, '1RL1', 'SN-KEEP-1');

        $this->postJson(self::URL, $this->payload($category->id, [1], [1], 0), $headers)->assertStatus(201);
        $this->assertSame('1RL1', $tire->refresh()->current_position);
        $this->assertSame('INSTALLED', $tire->current_status);
    }

    public function test_history_stays_intact_after_the_tire_is_removed_and_the_position_retired(): void
    {
        [$tenant, $category, $vehicle, $headers] = $this->scenario();
        $this->postJson(self::URL, $this->payload($category->id, [1], [2], 1), $headers)->assertStatus(201);
        $tire = $this->installTire($tenant, $vehicle, $headers, '1RL2', 'SN-HIST-1');
        $this->postJson(self::URL, $this->payload($category->id, [1], [1], 1), $headers)->assertStatus(422);

        $this->postJson("/api/v1/app/tires/{$tire->id}/remove", ['removal_reason' => 'Configuration change', 'disposition' => 'REUSE'], $headers)->assertSuccessful();
        $this->postJson(self::URL, $this->payload($category->id, [1], [1], 1), $headers)->assertStatus(201);

        // The installation history still points at 1RL2, and 1RL2 still exists (RETIRED).
        $installation = TireInstallation::query()->withoutGlobalScopes()->where('tire_id', $tire->id)->sole();
        $this->assertSame('1RL2', $installation->wheel_position);
        $this->assertNotNull($installation->removed_at);
        $this->assertDatabaseHas('wheel_configurations', ['tenant_id' => $tenant->id, 'vehicle_category_id' => $category->id, 'position_code' => '1RL2', 'status' => 'RETIRED']);

        // Retired positions are hidden by default, available on request, and no longer installable.
        $codes = collect($this->getJson("/api/v1/app/wheel-configurations?vehicle_category_id={$category->id}", $headers)->assertOk()->json('data'))->pluck('position_code');
        $this->assertNotContains('1RL2', $codes);
        $this->assertContains('1RL1', $codes);
        $all = collect($this->getJson("/api/v1/app/wheel-configurations?vehicle_category_id={$category->id}&include_retired=1", $headers)->assertOk()->json('data'))->pluck('position_code');
        $this->assertContains('1RL2', $all);

        $tire2 = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $tire->product_id, 'serial_number' => 'SN-HIST-2', 'current_status' => 'IN_STOCK']);
        $this->postJson("/api/v1/app/tires/{$tire2->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => '1RL2', 'odometer' => 1100], $headers)
            ->assertStatus(422)->assertJsonPath('message', "'1RL2' is not a configured wheel position for this vehicle's category.");
        $this->postJson("/api/v1/app/tires/{$tire2->id}/install", ['vehicle_id' => $vehicle->id, 'wheel_position' => '1RL1', 'odometer' => 1100], $headers)->assertStatus(201);
    }

    public function test_the_three_truck_configuration_types_are_distinct_versions(): void
    {
        [$tenant, $category, , $headers] = $this->scenario();

        $expected = ['NON_TRAILER' => '22.222', 'TRAILER' => '+22.222', 'SEMI_TRAILER' => '-22.222'];
        $number = 0;
        foreach ($expected as $type => $code) {
            $res = $this->postJson(self::URL, $this->payload($category->id, [2, 2], [2, 2, 2], 2, 'TRUCK', $type), $headers)->assertStatus(201);
            $res->assertJsonPath('data.version.config_code', $code)
                ->assertJsonPath('data.version.truck_configuration_type', $type)
                ->assertJsonPath('data.version.vehicle_type', 'TRUCK')
                ->assertJsonPath('data.version.version_number', ++$number)
                ->assertJsonPath('data.version.total_axles', 5)
                ->assertJsonPath('data.version.total_wheels', 22);
            if ($number > 1) {
                // Same axles: only the configuration type changed, every position is UNCHANGED.
                $res->assertJsonPath('data.diff.added', [])->assertJsonPath('data.diff.removed', []);
                $this->assertCount(22, $res->json('data.diff.unchanged'));
            }
        }

        $this->postJson(self::URL, $this->payload($category->id, [2, 2], [2, 2, 2], 2, 'TRUCK', 'SEMI_TRAILER'), $headers)->assertOk()->assertJsonPath('data.created', false);
        $versions = $this->getJson(self::URL."?vehicle_category_id={$category->id}", $headers)->assertOk()->json('data');
        $this->assertSame(['-22.222', '+22.222', '22.222'], array_column($versions, 'config_code'));
        $this->assertSame(['ACTIVE', 'SUPERSEDED', 'SUPERSEDED'], array_column($versions, 'status'));
        $this->assertSame(22, WheelConfiguration::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'ACTIVE')->count());
    }

    public function test_config_code_is_regenerated_by_the_server_and_a_mismatch_is_rejected(): void
    {
        [$tenant, $category, , $headers] = $this->scenario();
        $payload = $this->payload($category->id, [2, 2], [2, 2, 2], 0, 'TRUCK', 'TRAILER');

        $this->postJson(self::URL, $payload + ['config_code' => '22.222'], $headers)->assertStatus(422)->assertJsonValidationErrors('config_code');
        $this->postJson(self::URL, $payload + ['config_code' => '-22.222'], $headers)->assertStatus(422)->assertJsonValidationErrors('config_code');
        $this->assertSame(0, WheelConfigurationVersion::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());

        $this->postJson(self::URL, $payload + ['config_code' => '+22.222'], $headers)->assertStatus(201)->assertJsonPath('data.version.config_code', '+22.222');
    }

    public function test_invalid_configurations_are_rejected_without_writes(): void
    {
        [$tenant, $category, , $headers] = $this->scenario();

        $this->postJson(self::URL, $this->payload($category->id, [2], [2], 0, 'TRUCK'), $headers)->assertStatus(422)->assertJsonValidationErrors('truck_configuration_type');
        $this->postJson(self::URL, $this->payload($category->id, [2], [2], 0, 'BUS', 'TRAILER'), $headers)->assertStatus(422)->assertJsonValidationErrors('truck_configuration_type');
        $this->postJson(self::URL, $this->payload($category->id, [], [2], 0), $headers)->assertStatus(422)->assertJsonValidationErrors('front_axles');
        $this->postJson(self::URL, $this->payload($category->id, [5], [2], 0), $headers)->assertStatus(422)->assertJsonValidationErrors('front_axles.0');
        $this->postJson(self::URL, $this->payload($category->id, [1], [1], 5), $headers)->assertStatus(422)->assertJsonValidationErrors('spare_tires');
        $this->postJson(self::URL, $this->payload((string) Str::uuid(), [1], [1], 0), $headers)->assertStatus(422)->assertJsonValidationErrors('vehicle_category_id');
        $this->assertSame(0, WheelConfigurationVersion::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
    }

    public function test_preview_reports_the_diff_without_writing(): void
    {
        [$tenant, $category, , $headers] = $this->scenario();
        $this->postJson(self::URL, $this->payload($category->id, [1], [2], 1), $headers)->assertStatus(201);

        $res = $this->postJson(self::URL.'/preview', $this->payload($category->id, [1], [1, 1], 0), $headers)->assertOk();
        $res->assertJsonPath('data.changed', true)->assertJsonPath('data.can_save', true)->assertJsonPath('data.blockers', [])
            ->assertJsonPath('data.configuration.config_code', '1.11')
            ->assertJsonPath('data.active_version.version_number', 1);
        $this->assertEqualsCanonicalizing(['1RL2', '1RR2', 'S1'], $res->json('data.diff.removed'));
        $this->assertSame(1, WheelConfigurationVersion::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count());
        $this->assertSame(0, WheelConfiguration::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('status', 'RETIRED')->count());

        $this->postJson(self::URL.'/preview', $this->payload($category->id, [1], [2], 1), $headers)->assertOk()->assertJsonPath('data.changed', false);
    }

    public function test_platform_default_positions_are_diffed_but_never_modified(): void
    {
        [$tenant, $category, $vehicle, $headers] = $this->scenario();
        $platform = WheelConfiguration::query()->create(['tenant_id' => null, 'vehicle_category_id' => $category->id, 'position_code' => 'FRONT_LEFT', 'label' => 'Front Left', 'sequence' => 1]);
        $this->installTire($tenant, $vehicle, $headers, 'FRONT_LEFT', 'SN-LEGACY-1');

        $res = $this->postJson(self::URL, $this->payload($category->id, [1], [1], 0), $headers)->assertStatus(422);
        $res->assertJsonPath('blockers.0.position_code', 'FRONT_LEFT')->assertJsonPath('blockers.0.reason', 'REMOVED');

        $tire = Tire::query()->where('serial_number', 'SN-LEGACY-1')->sole();
        $this->postJson("/api/v1/app/tires/{$tire->id}/remove", ['removal_reason' => 'Migrating layout', 'disposition' => 'REUSE'], $headers)->assertSuccessful();
        $this->postJson(self::URL, $this->payload($category->id, [1], [1], 0), $headers)->assertStatus(201)->assertJsonPath('data.diff.removed', ['FRONT_LEFT']);

        $this->assertSame('ACTIVE', $platform->fresh()->status);
        $codes = collect($this->getJson("/api/v1/app/wheel-configurations?vehicle_category_id={$category->id}", $headers)->json('data'))->pluck('position_code')->all();
        $this->assertEqualsCanonicalizing(['1FL1', '1FR1', '1RL1', '1RR1'], $codes);

        // Another tenant on the same platform category still gets the platform default.
        [, , , $otherHeaders] = $this->scenario();
        $otherCodes = collect($this->getJson("/api/v1/app/wheel-configurations?vehicle_category_id={$category->id}", $otherHeaders)->json('data'))->pluck('position_code')->all();
        $this->assertSame(['FRONT_LEFT'], $otherCodes);
    }

    public function test_installations_on_legacy_unconfigured_positions_also_block(): void
    {
        [$tenant, $category, $vehicle, $headers] = $this->scenario();
        // No configuration yet: installs are permissive (legacy behavior).
        $this->installTire($tenant, $vehicle, $headers, 'REAR_X', 'SN-FREE-1');

        $res = $this->postJson(self::URL, $this->payload($category->id, [1], [1], 0), $headers)->assertStatus(422);
        $res->assertJsonPath('blockers.0.position_code', 'REAR_X')->assertJsonPath('blockers.0.reason', 'NOT_IN_CONFIGURATION');
    }

    public function test_tenant_isolation(): void
    {
        [$tenantA, $category, $vehicleA, $headersA] = $this->scenario();
        [$tenantB, , , $headersB] = $this->scenario();
        $this->postJson(self::URL, $this->payload($category->id, [1], [2], 0), $headersA)->assertStatus(201);
        // Tenant A's tire on a position tenant B removes does not block tenant B.
        $this->installTire($tenantA, $vehicleA, $headersA, '1RL2', 'SN-ISO-A');

        $this->postJson(self::URL, $this->payload($category->id, [1], [1], 0), $headersB)->assertStatus(201)
            ->assertJsonPath('data.version.version_number', 1);
        $this->assertSame([$tenantB->id], $this->getJson(self::URL, $headersB)->assertOk()->json('data.*.tenant_id'));
        $this->assertSame('1.2', WheelConfigurationVersion::query()->withoutGlobalScopes()->where('tenant_id', $tenantA->id)->sole()->config_code);
        $this->assertSame(4, WheelConfiguration::query()->withoutGlobalScopes()->where('tenant_id', $tenantB->id)->count());

        // A category private to tenant A is not visible to tenant B.
        $privateCategory = $this->makeVehicleCategory(['tenant_id' => $tenantA->id, 'is_system' => false]);
        $this->postJson(self::URL, $this->payload($privateCategory->id, [1], [1], 0), $headersB)->assertStatus(422)->assertJsonValidationErrors('vehicle_category_id');
    }

    public function test_permissions_are_enforced(): void
    {
        [, $category, , $headers] = $this->scenario(['tire.view']);

        $this->postJson(self::URL, $this->payload($category->id, [1], [1], 0), $headers)->assertStatus(403);
        $this->postJson(self::URL.'/preview', $this->payload($category->id, [1], [1], 0), $headers)->assertStatus(403);
        $this->getJson(self::URL, $headers)->assertOk();
    }

    public function test_legacy_position_endpoints_cannot_change_a_versioned_configuration(): void
    {
        [$tenant, $category, , $headers] = $this->scenario();
        $this->postJson(self::URL, $this->payload($category->id, [1], [1], 0), $headers)->assertStatus(201);
        $row = $this->positions($tenant->id, $category->id)['1FL1'];

        $this->putJson("/api/v1/app/wheel-configurations/{$row->id}", ['label' => 'Changed'], $headers)->assertStatus(422);
        $this->deleteJson("/api/v1/app/wheel-configurations/{$row->id}", [], $headers)->assertStatus(422);
        $this->postJson('/api/v1/app/wheel-configurations', ['vehicle_category_id' => $category->id, 'position_code' => 'X1', 'label' => 'Extra'], $headers)->assertStatus(422);
        $this->assertSame('Front Axle 1 Left Wheel 1', $row->fresh()->label);
    }

    public function test_legacy_delete_retires_a_position_referenced_by_history_and_refuses_an_occupied_one(): void
    {
        [$tenant, $category, $vehicle, $headers] = $this->scenario();
        $row = WheelConfiguration::query()->create(['tenant_id' => $tenant->id, 'vehicle_category_id' => $category->id, 'position_code' => 'FL', 'label' => 'Front Left', 'sequence' => 1]);
        $tire = $this->installTire($tenant, $vehicle, $headers, 'FL', 'SN-LEG-DEL');

        $this->deleteJson("/api/v1/app/wheel-configurations/{$row->id}", [], $headers)->assertStatus(422);
        $this->postJson("/api/v1/app/tires/{$tire->id}/remove", ['removal_reason' => 'x', 'disposition' => 'REUSE'], $headers)->assertSuccessful();

        $this->deleteJson("/api/v1/app/wheel-configurations/{$row->id}", [], $headers)->assertOk()
            ->assertJsonPath('data.deleted', false)->assertJsonPath('data.retired', true);
        $this->assertSame('RETIRED', $row->fresh()->status);
    }
}
