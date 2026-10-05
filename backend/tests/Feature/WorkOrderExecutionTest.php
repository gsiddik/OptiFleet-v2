<?php

namespace Tests\Feature;

use App\Domain\WorkOrder\Services\WorkOrderService;
use Tests\TestCase;

class WorkOrderExecutionTest extends TestCase
{
    private function setUpWorkOrder(bool $start = true): array
    {
        $tenant = $this->makeTenant(['code' => 'WOX-'.\Illuminate\Support\Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'MAINTENANCE');
        $this->grantModule($tenant, 'WORKSHOP');
        $this->grantModule($tenant, 'WORK_ORDER');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id]);
        $mechanic = $this->makeWorker($tenant, $branch, $workshop, ['employee_code' => 'MEC-1']);

        [$user] = $this->makeTenantUser($tenant, []);
        $wo = app(WorkOrderService::class)->create($vehicle, [
            'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $user->id);

        if ($start) {
            app(WorkOrderService::class)->submit($wo);
            $wo = app(WorkOrderService::class)->approve($wo);
            $wo = app(WorkOrderService::class)->assign($wo);
            $wo = app(WorkOrderService::class)->schedule($wo);
            $wo = app(WorkOrderService::class)->start($this->withApprovedWorkspace($wo));
        }

        return [$tenant, $branch, $workshop, $vehicle, $wo, $mechanic];
    }

    /**
     * Findings/Diagnosis/Corrective Actions are a Draft-only scoping exercise
     * (WorkOrderExecutionService::assertFindingScopeEditable) — they must be
     * added while the WO is still Draft, then the WO progresses through its
     * normal lifecycle for the Jobs part of this flow.
     */
    public function test_diagnosis_and_job_flow(): void
    {
        [$tenant, , , , $wo] = $this->setUpWorkOrder(start: false);
        [, $token] = $this->makeTenantUser($tenant, ['diagnosis.manage', 'maintenance_job.manage', 'work_order.submit', 'work_order.approve', 'work_order.assign', 'work_order.schedule', 'work_order.start']);
        $headers = $this->authHeaders($token);

        $finding = $this->postJson("/api/v1/app/work-orders/{$wo->id}/findings", [
            'severity' => 'HIGH', 'description' => 'Worn brake pads.',
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/diagnoses", [
            'work_order_finding_id' => $finding->json('data.id'), 'root_cause' => 'Excessive wear.',
        ], $headers)->assertStatus(201);

        $wo = app(WorkOrderService::class)->submit($wo);
        $wo = app(WorkOrderService::class)->approve($wo);
        $wo = app(WorkOrderService::class)->assign($wo);
        $wo = app(WorkOrderService::class)->schedule($wo);
        $wo = app(WorkOrderService::class)->start($this->withApprovedWorkspace($wo));

        // Findings/Diagnosis are no longer addable once the WO has left Draft.
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/findings", [
            'severity' => 'LOW', 'description' => 'Too late.',
        ], $headers)->assertStatus(422);

        $job = $this->postJson("/api/v1/app/work-orders/{$wo->id}/jobs", [
            'description' => 'Replace brake pads.', 'estimated_hours' => 1.5,
        ], $headers)->assertStatus(201);
        $this->assertSame('PENDING', $job->json('data.status'));

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/jobs/{$job->json('data.id')}/status", [
            'status' => 'ASSIGNED',
        ], $headers)->assertOk()->assertJsonPath('data.status', 'ASSIGNED');

        // Invalid job status jump.
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/jobs/{$job->json('data.id')}/status", [
            'status' => 'COMPLETED',
        ], $headers)->assertStatus(422);
    }

    public function test_finding_and_diagnosis_can_be_deleted_in_draft_only(): void
    {
        [$tenant, , , , $wo] = $this->setUpWorkOrder(start: false);
        [, $token] = $this->makeTenantUser($tenant, ['diagnosis.manage', 'work_order.submit']);
        $headers = $this->authHeaders($token);

        $finding = $this->postJson("/api/v1/app/work-orders/{$wo->id}/findings", [
            'severity' => 'HIGH', 'description' => 'Worn brake pads.',
        ], $headers)->assertStatus(201)->json('data');

        $diagnosis = $this->postJson("/api/v1/app/work-orders/{$wo->id}/diagnoses", [
            'work_order_finding_id' => $finding['id'], 'root_cause' => 'Excessive wear.',
        ], $headers)->assertStatus(201)->json('data');

        $action = $this->postJson("/api/v1/app/work-orders/{$wo->id}/corrective-actions", [
            'work_order_diagnosis_id' => $diagnosis['id'], 'action_description' => 'Replace pads.',
        ], $headers)->assertStatus(201)->json('data');

        $this->deleteJson("/api/v1/app/work-orders/{$wo->id}/corrective-actions/{$action['id']}", [], $headers)->assertOk();
        $this->deleteJson("/api/v1/app/work-orders/{$wo->id}/diagnoses/{$diagnosis['id']}", [], $headers)->assertOk();
        $this->deleteJson("/api/v1/app/work-orders/{$wo->id}/findings/{$finding['id']}", [], $headers)->assertOk();

        $wo = app(WorkOrderService::class)->submit($wo);
        $finding2 = $this->postJson("/api/v1/app/work-orders/{$wo->id}/findings", [
            'severity' => 'LOW', 'description' => 'Second finding, added pre-submit test only.',
        ], $headers);
        $finding2->assertStatus(422);
    }

    public function test_worker_workload_counts_active_job_assignments(): void
    {
        [$tenant, , , , $wo, $mechanic] = $this->setUpWorkOrder();

        [, $token] = $this->makeTenantUser($tenant, ['worker.assign', 'worker.view']);
        $headers = $this->authHeaders($token);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/mechanics", [
            'worker_id' => $mechanic->id, 'role' => 'PRIMARY',
        ], $headers)->assertStatus(201);

        $workload = $this->getJson('/api/v1/app/workers/workload', $headers)->assertOk();
        $row = collect($workload->json('data'))->firstWhere('id', $mechanic->id);
        $this->assertNotNull($row);
        $this->assertSame(1, $row['active_job_count']);
    }

    public function test_mechanic_assignment_and_cross_workshop_restriction(): void
    {
        [$tenant, $branch, $workshop, , $wo, $mechanic] = $this->setUpWorkOrder();
        $otherWorkshop = $this->makeWorkshop($tenant, $branch);
        $outsideMechanic = $this->makeWorker($tenant, $branch, $otherWorkshop, ['employee_code' => 'MEC-OUTSIDE']);

        [, $token] = $this->makeTenantUser($tenant, ['worker.assign']);
        $headers = $this->authHeaders($token);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/mechanics", [
            'worker_id' => $mechanic->id, 'role' => 'PRIMARY',
        ], $headers)->assertStatus(201);

        // A worker from a different workshop must be rejected outright, not just hidden in the UI.
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/mechanics", [
            'worker_id' => $outsideMechanic->id, 'role' => 'ASSISTANT',
        ], $headers)->assertStatus(422);
    }

    public function test_labor_timer_sequencing(): void
    {
        [$tenant, , , , $wo, $mechanic] = $this->setUpWorkOrder();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_job.manage']);
        $headers = $this->authHeaders($token);

        $job = $this->postJson("/api/v1/app/work-orders/{$wo->id}/jobs", [
            'description' => 'Oil change.',
        ], $headers)->assertStatus(201)->json('data');

        $start = $this->postJson("/api/v1/app/work-orders/{$wo->id}/jobs/{$job['id']}/labor/start", [
            'worker_id' => $mechanic->id,
        ], $headers)->assertStatus(201);
        $logId = $start->json('data.id');
        $this->assertSame('RUNNING', $start->json('data.status'));

        // Can't start a second concurrent log for the same worker+job.
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/jobs/{$job['id']}/labor/start", [
            'worker_id' => $mechanic->id,
        ], $headers)->assertStatus(422);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/jobs/{$job['id']}/labor/{$logId}/pause", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'PAUSED');

        // Can't pause an already-paused log.
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/jobs/{$job['id']}/labor/{$logId}/pause", [], $headers)->assertStatus(422);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/jobs/{$job['id']}/labor/{$logId}/resume", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'RUNNING');

        $finish = $this->postJson("/api/v1/app/work-orders/{$wo->id}/jobs/{$job['id']}/labor/{$logId}/finish", [], $headers)
            ->assertOk();
        $this->assertSame('FINISHED', $finish->json('data.status'));
        $this->assertNotNull($finish->json('data.actual_minutes'));

        // Can't finish an already-finished log.
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/jobs/{$job['id']}/labor/{$logId}/finish", [], $headers)->assertStatus(422);
    }

    public function test_additional_work_request_and_approval_creates_job(): void
    {
        [$tenant, , , , $wo] = $this->setUpWorkOrder();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_job.manage', 'work_order.approve', 'work_order.view']);
        $headers = $this->authHeaders($token);

        $request = $this->postJson("/api/v1/app/work-orders/{$wo->id}/additional-works", [
            'description' => 'Found damaged bearing, needs replacement.',
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/additional-works/{$request->json('data.id')}/decide", [
            'approve' => true,
        ], $headers)->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $jobs = $this->getJson("/api/v1/app/work-orders/{$wo->id}", $headers)->assertOk()->json('data.jobs');
        $this->assertCount(1, $jobs);
    }
}
