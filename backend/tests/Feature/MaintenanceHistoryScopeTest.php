<?php

namespace Tests\Feature;

use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Maintenance History (global) is scoped by the user's data scope — the whole tenant or the
 * assigned branch — without any vehicle selection; Vehicle History stays vehicle-specific and
 * refuses vehicles outside the scope.
 */
class MaintenanceHistoryScopeTest extends TestCase
{
    private function scenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'MHS-'.Str::random(4)]);
        foreach (['VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $category = $this->makeVehicleCategory();
        $branches = ['A' => $this->makeBranch($tenant, ['name' => 'Branch A']), 'B' => $this->makeBranch($tenant, ['name' => 'Branch B'])];
        $vehicles = [];
        foreach ($branches as $code => $branch) {
            $workshop = $this->makeWorkshop($tenant, $branch);
            foreach ([1, 2] as $n) {
                $vehicle = $this->makeVehicle($tenant, $branch, $category, ['registration_number' => "B {$code}{$n} MHS", 'default_workshop_id' => $workshop->id]);
                $vehicles["{$code}{$n}"] = $vehicle;
                app(WorkOrderService::class)->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000], null);
                Breakdown::query()->create([
                    'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicle->id, 'reported_at' => now()->subDays($n),
                    'location' => 'Depot', 'severity' => 'MINOR', 'description' => 'Flat tyre', 'status' => 'REPORTED',
                ]);
            }
        }
        [, $adminToken] = $this->makeTenantUser($tenant, ['maintenance_history.view', 'vehicle.view']);
        [, $branchToken] = $this->makeTenantUser($tenant, ['maintenance_history.view', 'vehicle.view'], ['BRANCH' => $branches['A']->id]);

        return [$tenant, $branches, $vehicles, $this->authHeaders($adminToken), $this->authHeaders($branchToken)];
    }

    private function registrations(array $rows): array
    {
        return collect($rows)->pluck('vehicle.registration_number')->unique()->sort()->values()->all();
    }

    public function test_tenant_admin_sees_every_vehicle_of_every_branch_without_selecting_one(): void
    {
        [, , , $admin] = $this->scenario();
        $response = $this->getJson('/api/v1/app/maintenance-history?per_page=100', $admin)->assertOk();
        $this->assertSame(['B A1 MHS', 'B A2 MHS', 'B B1 MHS', 'B B2 MHS'], $this->registrations($response->json('data')));
        $this->assertSame(8, $response->json('meta.total')); // a Work Order and a breakdown per vehicle
        $first = $response->json('data.0');
        $this->assertArrayHasKey('branch_name', $first['vehicle']);
        $this->assertNotEmpty($first['summary']);
        // Newest first.
        $dates = array_column($response->json('data'), 'at');
        $sorted = $dates;
        rsort($sorted);
        $this->assertSame($sorted, $dates);
    }

    public function test_branch_admin_sees_only_their_branch(): void
    {
        [, $branches, , , $branchAdmin] = $this->scenario();
        $rows = $this->getJson('/api/v1/app/maintenance-history?per_page=100', $branchAdmin)->assertOk()->json('data');
        $this->assertSame(['B A1 MHS', 'B A2 MHS'], $this->registrations($rows));
        // A branch filter outside the scope is refused instead of silently widened.
        $this->getJson("/api/v1/app/maintenance-history?branch_id={$branches['B']->id}", $branchAdmin)->assertStatus(403);
    }

    public function test_vehicle_history_returns_only_the_selected_vehicle_and_checks_scope(): void
    {
        [, , $vehicles, $admin, $branchAdmin] = $this->scenario();
        $rows = $this->getJson("/api/v1/app/vehicles/{$vehicles['A1']->id}/history", $branchAdmin)->assertOk()->json('data');
        $this->assertSame(['B A1 MHS'], $this->registrations($rows));
        $this->assertCount(2, $rows);

        // Direct API access to a vehicle of another branch is refused (both endpoints).
        $this->getJson("/api/v1/app/vehicles/{$vehicles['B1']->id}/history", $branchAdmin)->assertStatus(403);
        $this->getJson("/api/v1/app/maintenance-history?vehicle_id={$vehicles['B1']->id}", $branchAdmin)->assertStatus(403);
        // The global page filtered to one vehicle.
        $this->assertSame(['B B1 MHS'], $this->registrations($this->getJson("/api/v1/app/maintenance-history?vehicle_id={$vehicles['B1']->id}", $admin)->json('data')));
    }

    public function test_other_tenants_history_never_leaks(): void
    {
        [, , , $admin] = $this->scenario();
        [, , $otherVehicles] = $this->scenario();
        $rows = $this->getJson('/api/v1/app/maintenance-history?per_page=100', $admin)->assertOk()->json('data');
        $this->assertNotContains($otherVehicles['A1']->id, array_column($rows, 'vehicle_id'));
        $this->getJson("/api/v1/app/maintenance-history?vehicle_id={$otherVehicles['A1']->id}", $admin)->assertStatus(404);
    }

    public function test_filters_and_pagination_use_a_constant_number_of_queries(): void
    {
        [$tenant, , $vehicles, $admin] = $this->scenario();
        // Many more vehicles' history must not add queries (no N+1).
        $category = $this->makeVehicleCategory();
        $branch = $this->makeBranch($tenant);
        for ($i = 0; $i < 15; $i++) {
            $vehicle = $this->makeVehicle($tenant, $branch, $category, ['registration_number' => "B X{$i} MHS"]);
            Breakdown::query()->create(['tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicle->id, 'reported_at' => now(), 'location' => 'Depot', 'severity' => 'MAJOR', 'description' => 'x', 'status' => 'REPORTED']);
        }
        $this->getJson('/api/v1/app/maintenance-history?per_page=1', $admin)->assertOk(); // warm the auth / permission caches
        DB::enableQueryLog();
        $this->getJson('/api/v1/app/maintenance-history?per_page=50', $admin)->assertOk();
        $queries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->getJson('/api/v1/app/maintenance-history?per_page=5', $admin)->assertOk();
        $this->assertSame($queries, count(DB::getQueryLog()), 'page size changes the query count');
        DB::disableQueryLog();

        $this->assertSame(['BREAKDOWN'], collect($this->getJson('/api/v1/app/maintenance-history?type=BREAKDOWN&per_page=100', $admin)->json('data'))->pluck('type')->unique()->values()->all());
        $this->assertSame(['B A2 MHS'], $this->registrations($this->getJson('/api/v1/app/maintenance-history?search=A2', $admin)->json('data')));
        $this->assertSame(5, count($this->getJson('/api/v1/app/maintenance-history?per_page=5', $admin)->json('data')));
        $this->assertGreaterThan(1, $this->getJson('/api/v1/app/maintenance-history?per_page=5', $admin)->json('meta.last_page'));
        $this->assertNotNull($vehicles['A1']);
        $this->assertInstanceOf(Vehicle::class, $vehicles['B2']);
    }
}
