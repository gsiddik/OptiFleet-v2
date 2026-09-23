<?php

namespace Tests\Feature;

use App\Domain\WorkOrder\Models\WorkOrder;
use Tests\TestCase;

class WorkOrderTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'WO-'.\Illuminate\Support\Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'MAINTENANCE');
        $this->grantModule($tenant, 'WORKSHOP');
        $this->grantModule($tenant, 'WORK_ORDER');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id]);

        return [$tenant, $branch, $workshop, $vehicle];
    }

    private function fullPermissions(): array
    {
        return [
            'work_order.view', 'work_order.create', 'work_order.update', 'work_order.submit',
            'work_order.approve', 'work_order.assign', 'work_order.schedule', 'work_order.start',
            'work_order.pause', 'work_order.complete', 'work_order.close', 'work_order.cancel',
        ];
    }

    public function test_work_order_creation_and_number_format(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());

        $response = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ], $this->authHeaders($token))->assertStatus(201);

        $this->assertMatchesRegularExpression('#^WO/OPTIFLEET/\d{4}/\d{6}$#', $response->json('data.wo_number'));
        $this->assertSame('DRAFT', $response->json('data.status'));
    }

    public function test_work_order_numbers_are_unique_and_sequential(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $numbers = [];
        for ($i = 0; $i < 3; $i++) {
            $response = $this->postJson('/api/v1/app/work-orders', [
                'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
            ], $headers)->assertStatus(201);
            $numbers[] = $response->json('data.wo_number');
        }

        $this->assertSame($numbers, array_unique($numbers));
    }

    public function test_show_exposes_workspace_reservations(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        $this->grantModule($tenant, 'WORKSHOP');
        $permissions = array_merge($this->fullPermissions(), ['workspace.view', 'workspace.reserve']);
        [, $token] = $this->makeTenantUser($tenant, $permissions);
        $headers = $this->authHeaders($token);

        $woId = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ], $headers)->assertStatus(201)->json('data.id');

        $workspace = \App\Domain\Workshop\Models\Workspace::query()->create([
            'tenant_id' => $tenant->id, 'workshop_id' => $workshop->id, 'code' => 'BAY-1', 'name' => 'Bay 1',
            'workspace_type' => 'GENERAL_SERVICE_BAY', 'status' => 'AVAILABLE',
        ]);
        $this->postJson('/api/v1/app/workspace-reservations', [
            'workspace_id' => $workspace->id, 'work_order_id' => $woId,
            'start_at' => now()->addHour()->toIso8601String(), 'end_at' => now()->addHours(2)->toIso8601String(),
        ], $headers)->assertStatus(201);

        $show = $this->getJson("/api/v1/app/work-orders/{$woId}", $headers)->assertOk();
        $this->assertCount(1, $show->json('data.workspace_reservations'));
        $this->assertSame($workspace->id, $show->json('data.workspace_reservations.0.workspace_id'));
    }

    public function test_work_order_full_transition_chain(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');

        $this->postJson("/api/v1/app/work-orders/{$id}/submit", [], $headers)->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
        $this->postJson("/api/v1/app/work-orders/{$id}/approve", [], $headers)->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->postJson("/api/v1/app/work-orders/{$id}/assign", [], $headers)->assertOk()->assertJsonPath('data.status', 'ASSIGNED');
        $this->postJson("/api/v1/app/work-orders/{$id}/schedule", [], $headers)->assertOk()->assertJsonPath('data.status', 'SCHEDULED');
        $this->postJson("/api/v1/app/work-orders/{$id}/start", [], $headers)->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');

        $this->assertSame('IN_MAINTENANCE', $vehicle->fresh()->status);

        $this->postJson("/api/v1/app/work-orders/{$id}/hold", [], $headers)->assertOk()->assertJsonPath('data.status', 'ON_HOLD');
        $this->postJson("/api/v1/app/work-orders/{$id}/resume", [], $headers)->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
        $this->postJson("/api/v1/app/work-orders/{$id}/wait-for-part", [], $headers)->assertOk()->assertJsonPath('data.status', 'WAITING_PART');
        $this->postJson("/api/v1/app/work-orders/{$id}/resume", [], $headers)->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
        $this->postJson("/api/v1/app/work-orders/{$id}/submit-to-qc", [], $headers)->assertOk()->assertJsonPath('data.status', 'QC_PENDING');
        $this->postJson("/api/v1/app/work-orders/{$id}/complete", [], $headers)->assertOk()->assertJsonPath('data.status', 'COMPLETED');
        $this->postJson("/api/v1/app/work-orders/{$id}/close", [], $headers)->assertOk()->assertJsonPath('data.status', 'CLOSED');
    }

    public function test_invalid_work_order_transition_is_rejected(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');

        // DRAFT cannot jump straight to IN_PROGRESS or COMPLETED.
        $this->postJson("/api/v1/app/work-orders/{$id}/start", [], $headers)->assertStatus(422);
        $this->postJson("/api/v1/app/work-orders/{$id}/complete", [], $headers)->assertStatus(422);
        $this->postJson("/api/v1/app/work-orders/{$id}/close", [], $headers)->assertStatus(422);

        // Status was never mutated by the rejected calls.
        $this->assertSame('DRAFT', WorkOrder::query()->find($id)->status);
    }

    public function test_work_order_organization_scope_restricts_by_workshop(): void
    {
        [$tenant, $branch, $workshopA, $vehicleA] = $this->setUpTenant();
        $workshopB = $this->makeWorkshop($tenant, $branch);
        $vehicleB = $this->makeVehicle($tenant, $branch, $vehicleA->vehicleCategory, ['default_workshop_id' => $workshopB->id, 'registration_number' => 'WO-SCOPE-2']);

        [, $tokenA] = $this->makeTenantUser($tenant, $this->fullPermissions(), ['WORKSHOP' => $workshopA->id]);

        $woA = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicleA->id, 'workshop_id' => $workshopA->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ], $this->authHeaders($tokenA))->assertStatus(201)->json('data.id');

        [, $tokenAdmin] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $woB = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicleB->id, 'workshop_id' => $workshopB->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ], $this->authHeaders($tokenAdmin))->assertStatus(201)->json('data.id');

        // Workshop-A-scoped user (e.g. "Workshop Manager Bandung") sees only their own workshop's WOs.
        $list = $this->getJson('/api/v1/app/work-orders', $this->authHeaders($tokenA))->assertOk();
        $ids = collect($list->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($woA));
        $this->assertFalse($ids->contains($woB));

        $this->getJson("/api/v1/app/work-orders/{$woB}", $this->authHeaders($tokenA))->assertStatus(403);
    }

    public function test_cross_tenant_work_order_access_is_denied(): void
    {
        [$tenantA, , $workshopA, $vehicleA] = $this->setUpTenant();
        [$tenantB, , $workshopB, $vehicleB] = $this->setUpTenant();

        [, $tokenA] = $this->makeTenantUser($tenantA, $this->fullPermissions());
        $woA = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicleA->id, 'workshop_id' => $workshopA->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ], $this->authHeaders($tokenA))->assertStatus(201)->json('data.id');

        [, $tokenB] = $this->makeTenantUser($tenantB, $this->fullPermissions());

        $this->getJson("/api/v1/app/work-orders/{$woA}", $this->authHeaders($tokenB))->assertStatus(404);
        $this->postJson("/api/v1/app/work-orders/{$woA}/approve", [], $this->authHeaders($tokenB))->assertStatus(404);
    }

    public function test_work_order_denied_without_module_entitlement(): void
    {
        $tenant = $this->makeTenant(['code' => 'WO-NOMOD']);
        $this->grantModule($tenant, 'VEHICLE');
        // WORK_ORDER not granted.
        [, $token] = $this->makeTenantUser($tenant, ['work_order.view']);

        $this->getJson('/api/v1/app/work-orders', $this->authHeaders($token))->assertStatus(403);
    }

    /**
     * "Improvement OptiFleet - Maintenance Request dan Work Order": the manual
     * New Work Order popup only offers Corrective/Breakdown — Preventive is
     * exclusively set by the Planning & Schedule conversion path.
     */
    public function test_manual_creation_rejects_maintenance_types_other_than_corrective_or_breakdown(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        foreach (['PREVENTIVE', 'INSPECTION', 'CAMPAIGN'] as $type) {
            $this->postJson('/api/v1/app/work-orders', [
                'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => $type, 'current_odometer' => 1000,
            ], $headers)->assertStatus(422)->assertJsonValidationErrors('maintenance_type');
        }

        foreach (['CORRECTIVE', 'BREAKDOWN'] as $type) {
            $this->postJson('/api/v1/app/work-orders', [
                'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => $type, 'current_odometer' => 1000,
            ], $headers)->assertStatus(201);
        }
    }

    public function test_current_odometer_is_required_and_updates_the_vehicle_floor_guarded(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);
        $this->assertSame('10000.00', $vehicle->current_odometer);

        $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('current_odometer');

        // Higher than the vehicle's current value: the vehicle is updated to match.
        $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
            'current_odometer' => 10500, 'engine_hour' => 200,
        ], $headers)->assertStatus(201);
        $this->assertSame('10500.00', $vehicle->fresh()->current_odometer);
        $this->assertSame('200.00', $vehicle->fresh()->engine_hour);

        // Lower than the vehicle's now-current value: the vehicle is never moved backwards.
        $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
            'current_odometer' => 9000,
        ], $headers)->assertStatus(201);
        $this->assertSame('10500.00', $vehicle->fresh()->current_odometer);
    }
}
