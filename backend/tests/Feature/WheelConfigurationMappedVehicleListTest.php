<?php

namespace Tests\Feature;

use App\Domain\Tire\Models\VehicleWheelConfigurationMapping;
use App\Domain\Tire\Models\WheelConfigurationMaster;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Wheels Configuration list: Number of Vehicle (from ACTIVE mappings) and the nested mapped-vehicle table. */
class WheelConfigurationMappedVehicleListTest extends TestCase
{
    private const MASTERS = '/api/v1/app/wheel-configuration-masters';

    private function master(array $headers, array $rear): WheelConfigurationMaster
    {
        $id = $this->postJson(self::MASTERS, ['vehicle_type' => 'PASSENGER_CAR', 'front_axles' => [1], 'rear_axles' => $rear, 'spare_tires' => 0], $headers)->assertStatus(201)->json('data.master.id');

        return WheelConfigurationMaster::query()->findOrFail($id);
    }

    private function map($tenant, $branch, $category, WheelConfigurationMaster $master, int $count, string $prefix): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $vehicle = $this->makeVehicle($tenant, $branch, $category, ['registration_number' => sprintf('%s %02d', $prefix, $i), 'brand' => 'Hino', 'model' => 'Ranger', 'vehicle_type' => 'Car']);
            VehicleWheelConfigurationMapping::query()->create([
                'tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'wheel_configuration_master_id' => $master->id,
                'wheel_configuration_version_id' => $master->current_version_id, 'status' => 'ACTIVE', 'mapped_at' => now(),
            ]);
        }
    }

    public function test_number_of_vehicle_and_nested_vehicles(): void
    {
        $tenant = $this->makeTenant(['code' => 'WCL-'.Str::random(4)]);
        foreach (['VEHICLE', 'TIRE'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $otherBranch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        [, $token] = $this->makeTenantUser($tenant, ['tire.view', 'tire.manage']);
        $headers = $this->authHeaders($token);

        $a = $this->master($headers, [1]);
        $b = $this->master($headers, [2]);
        $c = $this->master($headers, [1, 1]);
        $this->map($tenant, $branch, $category, $b, 3, 'B');
        $this->map($tenant, $branch, $category, $c, 10, 'C');
        // An ended mapping and a deleted vehicle never count.
        VehicleWheelConfigurationMapping::query()->where('wheel_configuration_master_id', $c->id)->first()->update(['status' => 'ENDED', 'ended_at' => now(), 'end_reason' => 'UNMAPPED']);
        $this->map($tenant, $otherBranch, $category, $c, 1, 'X');
        Vehicle::query()->where('registration_number', 'X 01')->first()->delete();

        $rows = collect($this->getJson(self::MASTERS, $headers)->assertOk()->json('data'))->keyBy('id');
        DB::enableQueryLog();
        $this->getJson(self::MASTERS, $headers)->assertOk(); // warm request: per-request auth/context queries settled
        $listQueries = count(DB::getQueryLog());
        DB::disableQueryLog();
        $this->assertSame([0, 3, 9], [$rows[$a->id]['mapped_vehicle_count'], $rows[$b->id]['mapped_vehicle_count'], $rows[$c->id]['mapped_vehicle_count']]);

        // No N+1: adding configurations does not add queries.
        $this->master($headers, [3]);
        $this->master($headers, [1, 2]);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson(self::MASTERS, $headers)->assertOk();
        $this->assertSame($listQueries, count(DB::getQueryLog()));
        DB::disableQueryLog();

        // Nested table: meaningful identifiers, ordered, paginated server-side; empty for A.
        $this->assertSame([], $this->getJson(self::MASTERS."/{$a->id}/mapped-vehicles", $headers)->assertOk()->json('data'));
        $nested = $this->getJson(self::MASTERS."/{$c->id}/mapped-vehicles?per_page=5", $headers)->assertOk();
        $nested->assertJsonPath('meta.total', 9)->assertJsonPath('meta.last_page', 2)->assertJsonCount(5, 'data');
        $first = $nested->json('data.0');
        $this->assertSame(['C 02', 'Hino', 'Ranger', 'Car', 1], [$first['registration_number'], $first['brand'], $first['model'], $first['vehicle_type'], $first['version_number']]);
        $this->assertNotNull($first['branch_name']);

        // Branch-scoped user: counts and rows only for visible vehicles.
        [, $scoped] = $this->makeTenantUser($tenant, ['tire.view'], ['BRANCH' => $otherBranch->id]);
        $scopedHeaders = $this->authHeaders($scoped);
        $scopedRows = collect($this->getJson(self::MASTERS, $scopedHeaders)->json('data'))->keyBy('id');
        $this->assertSame(0, $scopedRows[$c->id]['mapped_vehicle_count']);
        $this->assertSame([], $this->getJson(self::MASTERS."/{$c->id}/mapped-vehicles", $scopedHeaders)->json('data'));

        // Tenant isolation.
        $other = $this->makeTenant(['code' => 'WCO-'.Str::random(4)]);
        $this->grantModule($other, 'TIRE');
        [, $otherToken] = $this->makeTenantUser($other, ['tire.view']);
        $this->getJson(self::MASTERS."/{$c->id}/mapped-vehicles", $this->authHeaders($otherToken))->assertNotFound();
    }
}
