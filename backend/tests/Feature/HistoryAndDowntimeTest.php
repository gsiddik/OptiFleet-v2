<?php

namespace Tests\Feature;

use App\Domain\History\Services\DowntimeService;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Tests\TestCase;

class HistoryAndDowntimeTest extends TestCase
{
    public function test_vehicle_history_aggregates_events_across_domains(): void
    {
        $tenant = $this->makeTenant(['code' => 'HIST-'.\Illuminate\Support\Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'MAINTENANCE');
        $this->grantModule($tenant, 'WORKSHOP');
        $this->grantModule($tenant, 'WORK_ORDER');
        $this->grantModule($tenant, 'HISTORY');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id]);

        [$user] = $this->makeTenantUser($tenant, []);
        $workOrders = app(WorkOrderService::class);
        $wo = $workOrders->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], $user->id);

        \App\Domain\Breakdown\Models\Breakdown::query()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicle->id,
            'severity' => 'MINOR', 'description' => 'Test', 'reported_at' => now(), 'status' => 'REPORTED',
        ]);

        [, $token] = $this->makeTenantUser($tenant, ['maintenance_history.view']);
        $response = $this->getJson("/api/v1/app/vehicles/{$vehicle->id}/history", $this->authHeaders($token))->assertOk();

        $types = collect($response->json('data'))->pluck('type');
        $this->assertTrue($types->contains('WORK_ORDER'));
        $this->assertTrue($types->contains('BREAKDOWN'));
    }

    public function test_downtime_calculation_derives_from_existing_timestamps(): void
    {
        $tenant = $this->makeTenant(['code' => 'DWT-'.\Illuminate\Support\Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'WORKSHOP');
        $this->grantModule($tenant, 'WORK_ORDER');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id]);

        $breakdown = \App\Domain\Breakdown\Models\Breakdown::query()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicle->id,
            'severity' => 'MAJOR', 'description' => 'Test', 'reported_at' => now()->subHours(5),
            'downtime_start_at' => now()->subHours(5), 'status' => 'REPORTED',
        ]);

        [$user] = $this->makeTenantUser($tenant, []);
        $workOrders = app(WorkOrderService::class);
        $wo = $workOrders->create($vehicle, [
            'workshop_id' => $workshop->id, 'maintenance_type' => 'BREAKDOWN', 'breakdown_id' => $breakdown->id,
        ], $user->id);
        $workOrders->submit($wo);
        $wo = $workOrders->approve($wo);
        $wo = $workOrders->assign($wo);
        $wo = $workOrders->schedule($wo);
        $wo = $workOrders->start($this->withApprovedWorkspace($wo)); // started_at = now()

        $downtime = app(DowntimeService::class)->forWorkOrder($wo);

        $this->assertNotNull($downtime['breakdown_reported_at']);
        $this->assertNotNull($downtime['vehicle_off_road_at']);
        $this->assertNotNull($downtime['maintenance_started_at']);
        $this->assertGreaterThan(0, $downtime['response_time_minutes']);
        $this->assertNull($downtime['total_downtime_minutes']); // not yet released
    }
}
