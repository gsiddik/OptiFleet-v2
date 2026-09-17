<?php

namespace Tests\Feature;

use App\Domain\MaintenancePolicy\Models\MaintenancePackage;
use App\Domain\MaintenancePolicy\Models\MaintenanceSchedule;
use App\Domain\MasterData\Models\ComponentGroup;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Enhancement Section 12: manual "Add New Schedule" creation and the
 * schedule -> Maintenance Request conversion.
 */
class MaintenanceSchedulePlanningTest extends TestCase
{
    private function setUpTenant(array $overrides = []): array
    {
        $tenant = $this->makeTenant(array_merge(['code' => 'MSP-'.Str::random(4), 'workshop_working_days' => 6], $overrides));
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'MAINTENANCE');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['current_odometer' => 10000]);

        return [$tenant, $branch, $category, $vehicle];
    }

    private function makePeriodicPackage($tenant, string $periodBy = 'CALENDAR_DAY', int $schedulePeriod = 30): MaintenancePackage
    {
        $group = ComponentGroup::query()->create(['tenant_id' => null, 'code' => 'CG-'.Str::random(6), 'name' => 'Engine', 'is_system' => true, 'status' => 'ACTIVE']);
        $package = MaintenancePackage::query()->create([
            'tenant_id' => $tenant->id, 'code' => 'PM-'.Str::random(4), 'name' => 'Periodic Service',
            'maintenance_type' => 'PERIODIC', 'period_by' => $periodBy, 'schedule_period' => $schedulePeriod, 'status' => 'ACTIVE',
        ]);
        $package->componentGroups()->attach($group->id);

        return $package;
    }

    public function test_manual_schedule_creation_computes_next_due_date_with_working_day_adjustment(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant(['workshop_working_days' => 5]);
        $package = $this->makePeriodicPackage($tenant, 'CALENDAR_DAY', 2);
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_schedule.view', 'maintenance_schedule.create']);
        $headers = $this->authHeaders($token);

        // 2026-09-17 (Thu) + 2 days = 2026-09-19 (Saturday) -> next Monday 2026-09-21 (working_days=5).
        $response = $this->postJson('/api/v1/app/maintenance-schedules', [
            'vehicle_id' => $vehicle->id, 'maintenance_package_id' => $package->id, 'schedule_start_date' => '2026-09-17',
        ], $headers)->assertStatus(201);

        $this->assertStringStartsWith('2026-09-17', $response->json('data.schedule_start_date'));
        $this->assertStringStartsWith('2026-09-21', $response->json('data.next_due_date'));
        $this->assertSame('SCHEDULED', $response->json('data.status'));
        $this->assertNotNull($response->json('data.package_snapshot'));
    }

    public function test_schedule_creation_requires_workshop_working_days_to_be_set(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant(['workshop_working_days' => null]);
        $package = $this->makePeriodicPackage($tenant);
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_schedule.view', 'maintenance_schedule.create']);

        $this->postJson('/api/v1/app/maintenance-schedules', [
            'vehicle_id' => $vehicle->id, 'maintenance_package_id' => $package->id, 'schedule_start_date' => '2026-09-17',
        ], $this->authHeaders($token))->assertStatus(422);
    }

    public function test_schedule_creation_rejects_a_non_periodic_or_inactive_package(): void
    {
        [$tenant, $branch, $category, $vehicle] = $this->setUpTenant();
        $preventive = MaintenancePackage::query()->create([
            'tenant_id' => $tenant->id, 'code' => 'PM-PV', 'name' => 'Preventive', 'maintenance_type' => 'PREVENTIVE',
            'period_by' => 'ODOMETER', 'threshold_km' => 5000, 'status' => 'ACTIVE',
        ]);
        $draftPeriodic = $this->makePeriodicPackage($tenant);
        $draftPeriodic->update(['status' => 'DRAFT']);

        [, $token] = $this->makeTenantUser($tenant, ['maintenance_schedule.view', 'maintenance_schedule.create']);
        $headers = $this->authHeaders($token);

        $this->postJson('/api/v1/app/maintenance-schedules', [
            'vehicle_id' => $vehicle->id, 'maintenance_package_id' => $preventive->id, 'schedule_start_date' => '2026-09-17',
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('maintenance_package_id');

        $this->postJson('/api/v1/app/maintenance-schedules', [
            'vehicle_id' => $vehicle->id, 'maintenance_package_id' => $draftPeriodic->id, 'schedule_start_date' => '2026-09-17',
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('maintenance_package_id');
    }

    public function test_two_different_packages_cannot_be_scheduled_on_the_same_date_for_the_same_vehicle(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        $packageA = $this->makePeriodicPackage($tenant);
        $packageB = $this->makePeriodicPackage($tenant);
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_schedule.view', 'maintenance_schedule.create']);
        $headers = $this->authHeaders($token);

        $this->postJson('/api/v1/app/maintenance-schedules', [
            'vehicle_id' => $vehicle->id, 'maintenance_package_id' => $packageA->id, 'schedule_start_date' => '2026-10-01',
        ], $headers)->assertStatus(201);

        $this->postJson('/api/v1/app/maintenance-schedules', [
            'vehicle_id' => $vehicle->id, 'maintenance_package_id' => $packageB->id, 'schedule_start_date' => '2026-10-01',
        ], $headers)->assertStatus(422);
    }

    public function test_rescheduling_the_same_vehicle_and_package_updates_the_existing_row_not_a_duplicate(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        $package = $this->makePeriodicPackage($tenant);
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_schedule.view', 'maintenance_schedule.create']);
        $headers = $this->authHeaders($token);

        $this->postJson('/api/v1/app/maintenance-schedules', [
            'vehicle_id' => $vehicle->id, 'maintenance_package_id' => $package->id, 'schedule_start_date' => '2026-10-01',
        ], $headers)->assertStatus(201);
        $this->postJson('/api/v1/app/maintenance-schedules', [
            'vehicle_id' => $vehicle->id, 'maintenance_package_id' => $package->id, 'schedule_start_date' => '2026-10-05',
        ], $headers)->assertStatus(201);

        $count = MaintenanceSchedule::query()->where('vehicle_id', $vehicle->id)->where('maintenance_package_id', $package->id)->count();
        $this->assertSame(1, $count);
    }

    public function test_schedule_branch_scope_is_enforced_via_the_existing_data_scope_service(): void
    {
        [$tenant, $branchA, $category, $vehicleA] = $this->setUpTenant();
        $branchB = $this->makeBranch($tenant);
        $vehicleB = $this->makeVehicle($tenant, $branchB, $category, ['registration_number' => 'B-SCOPE-MS']);
        $package = $this->makePeriodicPackage($tenant);

        [, $token] = $this->makeTenantUser($tenant, ['maintenance_schedule.view', 'maintenance_schedule.create'], ['BRANCH' => $branchA->id]);
        $headers = $this->authHeaders($token);

        $this->postJson('/api/v1/app/maintenance-schedules', [
            'vehicle_id' => $vehicleA->id, 'maintenance_package_id' => $package->id, 'schedule_start_date' => '2026-10-01',
        ], $headers)->assertStatus(201);

        $this->postJson('/api/v1/app/maintenance-schedules', [
            'vehicle_id' => $vehicleB->id, 'maintenance_package_id' => $package->id, 'schedule_start_date' => '2026-10-02',
        ], $headers)->assertStatus(403);
    }

    public function test_converting_a_due_schedule_to_a_maintenance_request_is_idempotent(): void
    {
        [$tenant, $branch, $category, $vehicle] = $this->setUpTenant();
        $this->grantModule($tenant, 'CORE');
        $package = MaintenancePackage::query()->create([
            'tenant_id' => $tenant->id, 'code' => 'PM-DUE', 'name' => 'Due Package', 'maintenance_type' => 'PREVENTIVE',
            'period_by' => 'ODOMETER', 'threshold_km' => 5000, 'status' => 'ACTIVE',
        ]);
        $schedule = MaintenanceSchedule::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'maintenance_package_id' => $package->id,
            'next_due_odometer' => 10000, 'tolerance_odometer' => 500, 'status' => 'DUE',
        ]);

        [, $token] = $this->makeTenantUser($tenant, ['maintenance_schedule.view', 'maintenance_schedule.convert_maintenance_request']);
        $headers = $this->authHeaders($token);

        $first = $this->postJson("/api/v1/app/maintenance-schedules/{$schedule->id}/maintenance-request", [], $headers)->assertStatus(201);
        $this->assertSame('SCHEDULE', $first->json('data.source_type'));
        $this->assertSame($schedule->id, $first->json('data.source_schedule_id'));
        $this->assertSame('DUE', $schedule->fresh()->status);

        // A second attempt (double-click/retry) returns the same request, not a duplicate.
        $second = $this->postJson("/api/v1/app/maintenance-schedules/{$schedule->id}/maintenance-request", [], $headers)->assertOk();
        $this->assertSame($first->json('data.id'), $second->json('data.id'));

        $count = \App\Domain\MaintenanceRequest\Models\MaintenanceRequest::query()->where('source_schedule_id', $schedule->id)->count();
        $this->assertSame(1, $count);
    }

    public function test_conversion_rejected_for_a_schedule_not_yet_due(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        $package = $this->makePeriodicPackage($tenant);
        $schedule = MaintenanceSchedule::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'maintenance_package_id' => $package->id,
            'next_due_date' => now()->addMonth()->toDateString(), 'status' => 'UPCOMING',
        ]);

        [, $token] = $this->makeTenantUser($tenant, ['maintenance_schedule.view', 'maintenance_schedule.convert_maintenance_request']);

        $this->postJson("/api/v1/app/maintenance-schedules/{$schedule->id}/maintenance-request", [], $this->authHeaders($token))
            ->assertStatus(422);
    }
}
