<?php

namespace Tests\Feature;

use App\Domain\MaintenancePolicy\Models\MaintenancePackage;
use App\Domain\MaintenancePolicy\Models\MaintenanceSchedule;
use App\Domain\MaintenancePolicy\Models\VehicleMaintenanceProfile;
use App\Domain\MaintenancePolicy\Services\MaintenanceScheduleService;
use Tests\TestCase;

class MaintenancePolicyAndScheduleTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'MPS-'.\Illuminate\Support\Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'MAINTENANCE');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['current_odometer' => 45000]);

        return [$tenant, $branch, $category, $vehicle];
    }

    public function test_maintenance_policy_create_and_assign_generates_schedule(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_policy.view', 'maintenance_policy.manage', 'maintenance_schedule.view']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/maintenance-policies', [
            'code' => 'PM-1', 'name' => 'Oil Service', 'maintenance_type' => 'PREVENTIVE',
        ], $headers)->assertStatus(201);
        $packageId = $create->json('data.id');

        $this->postJson("/api/v1/app/maintenance-policies/{$packageId}/intervals", [
            'trigger_type' => 'ODOMETER', 'odometer_km' => 5000, 'tolerance_km' => 500,
        ], $headers)->assertStatus(201);

        $assign = $this->postJson("/api/v1/app/maintenance-policies/{$packageId}/assign", [
            'vehicle_id' => $vehicle->id,
        ], $headers)->assertStatus(201);

        $this->assertNotNull($assign->json('data.schedule.id'));
        $this->assertSame(50000, $assign->json('data.schedule.next_due_odometer'));
        // 45,000 -> 50,000 is 5,000 away, outside a 500 tolerance -> UPCOMING.
        $this->assertSame('UPCOMING', $assign->json('data.schedule.status'));
    }

    public function test_duplicate_schedule_generation_is_prevented(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();

        $package = MaintenancePackage::query()->create([
            'tenant_id' => $tenant->id, 'code' => 'PM-DUP', 'name' => 'Dup Test',
            'maintenance_type' => 'PREVENTIVE', 'status' => 'ACTIVE',
        ]);
        $package->intervals()->create(['trigger_type' => 'ODOMETER', 'odometer_km' => 5000, 'tolerance_km' => 500]);

        $profile = VehicleMaintenanceProfile::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'maintenance_package_id' => $package->id,
            'status' => 'ACTIVE', 'effective_from' => now()->toDateString(),
        ]);

        $service = app(MaintenanceScheduleService::class);
        $service->generateForProfile($profile->load(['vehicle', 'package.intervals']));
        $service->generateForProfile($profile->load(['vehicle', 'package.intervals']));

        // The unique(vehicle_id, maintenance_package_id) constraint means a
        // second generation call updates the existing row, never inserts a duplicate.
        $count = MaintenanceSchedule::query()->where('vehicle_id', $vehicle->id)->where('maintenance_package_id', $package->id)->count();
        $this->assertSame(1, $count);
    }

    public function test_maintenance_schedule_list_filters_by_status(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        MaintenanceSchedule::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id,
            'maintenance_package_id' => MaintenancePackage::query()->create([
                'tenant_id' => $tenant->id, 'code' => 'PM-OV', 'name' => 'Overdue Test', 'maintenance_type' => 'PREVENTIVE', 'status' => 'ACTIVE',
            ])->id,
            'next_due_odometer' => 40000, 'tolerance_odometer' => 500, 'status' => 'OVERDUE',
        ]);

        [, $token] = $this->makeTenantUser($tenant, ['maintenance_schedule.view']);
        $response = $this->getJson('/api/v1/app/maintenance-schedules?status=OVERDUE', $this->authHeaders($token))->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    public function test_maintenance_module_denied_without_entitlement(): void
    {
        $tenant = $this->makeTenant(['code' => 'MPS-NOMOD']);
        // MAINTENANCE module deliberately not granted.
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_policy.view']);

        $this->getJson('/api/v1/app/maintenance-policies', $this->authHeaders($token))->assertStatus(403);
    }
}
