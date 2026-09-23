<?php

namespace Tests\Feature;

use App\Domain\WorkOrder\Services\WorkOrderService;
use Tests\TestCase;

class QualityControlAndReleaseTest extends TestCase
{
    private function setUpWorkOrderAtQc(): array
    {
        $tenant = $this->makeTenant(['code' => 'QCR-'.\Illuminate\Support\Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'MAINTENANCE');
        $this->grantModule($tenant, 'WORKSHOP');
        $this->grantModule($tenant, 'WORK_ORDER');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id]);
        $mechanic = $this->makeWorker($tenant, $branch, $workshop, ['employee_code' => 'MEC-1', 'worker_type' => 'MECHANIC']);
        $qcInspector = $this->makeWorker($tenant, $branch, $workshop, ['employee_code' => 'QC-1', 'worker_type' => 'QC']);

        [$user] = $this->makeTenantUser($tenant, []);
        $workOrders = app(WorkOrderService::class);
        $wo = $workOrders->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], $user->id);
        $workOrders->submit($wo);
        $wo = $workOrders->approve($wo);
        $wo = $workOrders->assign($wo);
        $wo = $workOrders->schedule($wo);
        $wo = $workOrders->start($wo);

        app(\App\Domain\Workshop\Services\MechanicAssignmentService::class)->assign($wo, $mechanic, 'PRIMARY', null, $user->id);

        $wo = $workOrders->submitToQc($wo);

        return [$tenant, $branch, $workshop, $vehicle, $wo, $mechanic, $qcInspector];
    }

    public function test_qc_pass_flow_and_road_test(): void
    {
        [$tenant, , , $vehicle, $wo, , $qcInspector] = $this->setUpWorkOrderAtQc();
        [, $token] = $this->makeTenantUser($tenant, ['qc.view', 'qc.perform', 'qc.approve', 'work_order.complete', 'vehicle_release.perform']);
        $headers = $this->authHeaders($token);

        $start = $this->postJson("/api/v1/app/work-orders/{$wo->id}/qc/start", [
            'inspector_worker_id' => $qcInspector->id,
        ], $headers)->assertStatus(201);
        $inspectionId = $start->json('data.id');
        $this->assertSame('QC_STARTED', $start->json('data.status'));

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/qc/{$inspectionId}/pass", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'PASS');
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/qc/{$inspectionId}/complete", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'COMPLETED');

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/road-test", [
            'result' => 'PASS', 'start_odometer' => 1000, 'end_odometer' => 1010,
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/complete", [], $headers)->assertOk()->assertJsonPath('data.status', 'COMPLETED');

        $release = $this->postJson("/api/v1/app/work-orders/{$wo->id}/release", [
            'release_odometer' => 1010,
        ], $headers)->assertStatus(201);
        $this->assertNotNull($release->json('data.released_at'));

        $this->assertSame('ACTIVE', $vehicle->fresh()->status);
        $this->assertSame('CLOSED', $wo->fresh()->status);
    }

    public function test_qc_inspection_list_is_workshop_scoped(): void
    {
        $tenant = $this->makeTenant(['code' => 'QCS-'.\Illuminate\Support\Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'MAINTENANCE');
        $this->grantModule($tenant, 'WORKSHOP');
        $this->grantModule($tenant, 'WORK_ORDER');
        $branch = $this->makeBranch($tenant);
        $workshopA = $this->makeWorkshop($tenant, $branch, ['code' => 'WS-A']);
        $workshopB = $this->makeWorkshop($tenant, $branch, ['code' => 'WS-B']);
        $category = $this->makeVehicleCategory();

        [$user] = $this->makeTenantUser($tenant, []);
        $workOrders = app(WorkOrderService::class);

        $startQc = function ($workshop) use ($tenant, $branch, $category, $user, $workOrders) {
            $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id, 'registration_number' => 'QCS-'.\Illuminate\Support\Str::random(6)]);
            $wo = $workOrders->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], $user->id);
            $workOrders->submit($wo);
            $wo = $workOrders->approve($wo);
            $wo = $workOrders->assign($wo);
            $wo = $workOrders->schedule($wo);
            $wo = $workOrders->start($wo);
            $wo = $workOrders->submitToQc($wo);

            return app(\App\Domain\QualityControl\Services\QualityControlService::class)->start($wo, null, $user->id);
        };

        $inspectionA = $startQc($workshopA);
        $inspectionB = $startQc($workshopB);

        [, $scopedToken] = $this->makeTenantUser($tenant, ['qc.view'], ['WORKSHOP' => $workshopA->id]);

        $list = $this->getJson('/api/v1/app/qc-inspections', $this->authHeaders($scopedToken))->assertOk();
        $ids = collect($list->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($inspectionA->id));
        $this->assertFalse($ids->contains($inspectionB->id));
    }

    public function test_qc_fail_triggers_rework(): void
    {
        [$tenant, , , , $wo, , $qcInspector] = $this->setUpWorkOrderAtQc();
        [, $token] = $this->makeTenantUser($tenant, ['qc.perform', 'qc.reject']);
        $headers = $this->authHeaders($token);

        $start = $this->postJson("/api/v1/app/work-orders/{$wo->id}/qc/start", [
            'inspector_worker_id' => $qcInspector->id,
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/qc/{$start->json('data.id')}/fail", [
            'note' => 'Leak still present.',
        ], $headers)->assertOk()->assertJsonPath('data.status', 'FAIL');

        $this->assertSame('REWORK', $wo->fresh()->status);
    }

    public function test_mechanic_who_performed_work_cannot_be_qc_inspector(): void
    {
        [$tenant, , , , $wo, $mechanic] = $this->setUpWorkOrderAtQc();
        [, $token] = $this->makeTenantUser($tenant, ['qc.perform']);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/qc/start", [
            'inspector_worker_id' => $mechanic->id, // same worker who was assigned PRIMARY on this WO
        ], $this->authHeaders($token))->assertStatus(422);
    }

    public function test_vehicle_release_blocked_by_another_active_work_order(): void
    {
        [$tenant, $branch, $workshop, $vehicle, $wo, , $qcInspector] = $this->setUpWorkOrderAtQc();
        [, $token] = $this->makeTenantUser($tenant, ['qc.perform', 'qc.approve', 'work_order.complete', 'work_order.create', 'vehicle_release.perform']);
        $headers = $this->authHeaders($token);

        $start = $this->postJson("/api/v1/app/work-orders/{$wo->id}/qc/start", ['inspector_worker_id' => $qcInspector->id], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/qc/{$start->json('data.id')}/pass", [], $headers);
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/qc/{$start->json('data.id')}/complete", [], $headers);
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/complete", [], $headers)->assertOk();

        // A second, still-open WO exists for the same vehicle.
        $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/release", [], $headers)->assertStatus(422);
    }

    public function test_double_vehicle_release_is_prevented(): void
    {
        [$tenant, , , , $wo, , $qcInspector] = $this->setUpWorkOrderAtQc();
        [, $token] = $this->makeTenantUser($tenant, ['qc.perform', 'qc.approve', 'work_order.complete', 'vehicle_release.perform']);
        $headers = $this->authHeaders($token);

        $start = $this->postJson("/api/v1/app/work-orders/{$wo->id}/qc/start", ['inspector_worker_id' => $qcInspector->id], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/qc/{$start->json('data.id')}/pass", [], $headers);
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/qc/{$start->json('data.id')}/complete", [], $headers);
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/complete", [], $headers)->assertOk();

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/release", [], $headers)->assertStatus(201);
        // Second release attempt: WO is now CLOSED, not COMPLETED, so it's rejected up-front.
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/release", [], $headers)->assertStatus(422);
    }
}
