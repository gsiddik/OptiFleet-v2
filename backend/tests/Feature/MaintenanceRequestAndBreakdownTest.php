<?php

namespace Tests\Feature;

use App\Domain\MaintenanceRequest\Services\MaintenanceRequestService;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Tests\TestCase;

class MaintenanceRequestAndBreakdownTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'MRB-'.\Illuminate\Support\Str::random(4)]);
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

    public function test_maintenance_request_lifecycle(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, [
            'maintenance_request.view', 'maintenance_request.create', 'maintenance_request.review', 'maintenance_request.approve',
        ]);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/maintenance-requests', [
            'vehicle_id' => $vehicle->id, 'complaint' => 'Strange noise from engine.',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');
        $this->assertSame('DRAFT', $create->json('data.status'));
        $this->assertNotEmpty($create->json('data.request_number'));

        $this->postJson("/api/v1/app/maintenance-requests/{$id}/submit", [], $headers)->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/review", [], $headers)->assertOk()->assertJsonPath('data.status', 'UNDER_REVIEW');
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/approve", ['note' => 'ok'], $headers)->assertOk()->assertJsonPath('data.status', 'APPROVED');

        // Invalid transition: an approved request cannot be re-submitted.
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/submit", [], $headers)->assertStatus(422);
    }

    public function test_maintenance_request_rejection_requires_note(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_request.create', 'maintenance_request.review', 'maintenance_request.reject']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/maintenance-requests', [
            'vehicle_id' => $vehicle->id, 'complaint' => 'Test',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/submit", [], $headers);
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/review", [], $headers);

        $this->postJson("/api/v1/app/maintenance-requests/{$id}/reject", [], $headers)->assertStatus(422);
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/reject", ['note' => 'Not enough detail'], $headers)
            ->assertOk()->assertJsonPath('data.status', 'REJECTED');
    }

    public function test_approved_request_converts_to_work_order(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, [
            'maintenance_request.view', 'maintenance_request.create', 'maintenance_request.review', 'maintenance_request.approve',
            'maintenance_request.convert_work_order', 'work_order.view',
        ]);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/maintenance-requests', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'complaint' => 'Brake issue',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/submit", [], $headers);
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/review", [], $headers);
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/approve", [], $headers);

        $wo = $this->postJson("/api/v1/app/maintenance-requests/{$id}/work-order", [
            'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201);
        $this->assertNotEmpty($wo->json('data.wo_number'));

        $requestAfter = $this->getJson("/api/v1/app/maintenance-requests/{$id}", $headers)->assertOk();
        $this->assertSame('WORK_ORDER_CREATED', $requestAfter->json('data.status'));
    }

    public function test_duplicate_conversion_to_work_order_is_prevented(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [$user] = $this->makeTenantUser($tenant, []);

        $requests = app(MaintenanceRequestService::class);
        $mr = $requests->create($vehicle, ['workshop_id' => $workshop->id, 'source_type' => 'USER', 'complaint' => 'Test', 'status' => 'SUBMITTED'], $user->id);
        $mr = $requests->transition($mr, 'UNDER_REVIEW');
        $mr = $requests->transition($mr, 'APPROVED', $user->id);

        $workOrders = app(WorkOrderService::class);
        $workOrders->fromMaintenanceRequest($mr, ['maintenance_type' => 'CORRECTIVE'], $user->id);

        $this->expectException(\App\Domain\WorkOrder\Services\WorkOrderException::class);
        $workOrders->fromMaintenanceRequest($mr->fresh(), ['maintenance_type' => 'CORRECTIVE'], $user->id);
    }

    public function test_breakdown_lifecycle_and_conversion_to_work_order(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, [
            'breakdown.view', 'breakdown.report', 'breakdown.review', 'breakdown.resolve',
            'maintenance_request.approve', 'maintenance_request.review', 'maintenance_request.convert_work_order', 'work_order.view',
        ]);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/breakdowns', [
            'vehicle_id' => $vehicle->id, 'severity' => 'IMMOBILIZED', 'description' => 'Engine seized.',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');
        $this->assertSame('REPORTED', $create->json('data.status'));

        // Vehicle immediately flips to BREAKDOWN.
        $this->assertSame('BREAKDOWN', $vehicle->fresh()->status);

        $this->postJson("/api/v1/app/breakdowns/{$id}/verify", [], $headers)->assertOk()->assertJsonPath('data.status', 'VERIFIED');
        $this->postJson("/api/v1/app/breakdowns/{$id}/assess", [], $headers)->assertOk()->assertJsonPath('data.status', 'ASSESSED');
        $this->postJson("/api/v1/app/breakdowns/{$id}/require-repair", [], $headers)->assertOk()->assertJsonPath('data.status', 'REPAIR_REQUIRED');

        $mr = $this->postJson("/api/v1/app/breakdowns/{$id}/convert-to-request", [
            'workshop_id' => $workshop->id,
        ], $headers)->assertStatus(201);
        $this->assertSame('BREAKDOWN', $mr->json('data.source_type'));
        $this->assertSame($id, $mr->json('data.source_breakdown_id'));

        // Invalid transition: can't verify twice from REPAIR_REQUIRED.
        $this->postJson("/api/v1/app/breakdowns/{$id}/verify", [], $headers)->assertStatus(422);
    }

    public function test_maintenance_request_denied_without_module_entitlement(): void
    {
        $tenant = $this->makeTenant(['code' => 'MRB-NOMOD']);
        $this->grantModule($tenant, 'VEHICLE');
        // MAINTENANCE not granted.
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_request.view']);

        $this->getJson('/api/v1/app/maintenance-requests', $this->authHeaders($token))->assertStatus(403);
    }
}
