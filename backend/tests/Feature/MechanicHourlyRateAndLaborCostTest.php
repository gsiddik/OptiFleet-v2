<?php

namespace Tests\Feature;

use App\Domain\Workshop\Models\WorkOrderMechanicAssignment;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Findings/Diagnosis/Corrective Actions/Jobs/Mechanic Assignment cost
 * estimation gap: a mechanic's hourly rate must be snapshotted at
 * assignment time (never re-read live), and Estimated Labor Cost is
 * derived from a Job's estimated_hours x that snapshot.
 */
class MechanicHourlyRateAndLaborCostTest extends TestCase
{
    private function setUpWorkOrder(): array
    {
        $tenant = $this->makeTenant(['code' => 'MHR-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'MAINTENANCE');
        $this->grantModule($tenant, 'WORKSHOP');
        $this->grantModule($tenant, 'WORK_ORDER');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id]);
        [, $token] = $this->makeTenantUser($tenant, [
            'work_order.view', 'work_order.create', 'work_order.submit', 'work_order.approve',
            'work_order.assign', 'work_order.schedule', 'work_order.start',
            'maintenance_job.manage', 'worker.assign', 'work_order.estimate',
        ]);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');

        // Jobs can only be added once the Work Order is executable (ASSIGNED or beyond).
        $this->postJson("/api/v1/app/work-orders/{$id}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/assign", [], $headers)->assertOk();

        return [$tenant, $branch, $workshop, $vehicle, $id, $headers];
    }

    public function test_assigning_a_mechanic_snapshots_the_current_hourly_rate(): void
    {
        [$tenant, $branch, $workshop, , $woId, $headers] = $this->setUpWorkOrder();
        $worker = $this->makeWorker($tenant, $branch, $workshop, ['hourly_rate' => '75.5000']);

        $response = $this->postJson("/api/v1/app/work-orders/{$woId}/mechanics", [
            'worker_id' => $worker->id,
        ], $headers)->assertStatus(201);

        $assignment = WorkOrderMechanicAssignment::query()->findOrFail($response->json('data.id'));
        $this->assertSame('75.5000', (string) $assignment->hourly_rate_snapshot);
    }

    public function test_later_rate_change_does_not_affect_an_existing_snapshot(): void
    {
        [$tenant, $branch, $workshop, , $woId, $headers] = $this->setUpWorkOrder();
        $worker = $this->makeWorker($tenant, $branch, $workshop, ['hourly_rate' => '50.0000']);

        $response = $this->postJson("/api/v1/app/work-orders/{$woId}/mechanics", [
            'worker_id' => $worker->id,
        ], $headers)->assertStatus(201);
        $assignmentId = $response->json('data.id');

        $worker->update(['hourly_rate' => '999.0000']);

        $assignment = WorkOrderMechanicAssignment::query()->findOrFail($assignmentId);
        $this->assertSame('50.0000', (string) $assignment->hourly_rate_snapshot);
    }

    public function test_job_computed_labor_cost_uses_estimated_hours_times_snapshotted_rate(): void
    {
        [$tenant, $branch, $workshop, , $woId, $headers] = $this->setUpWorkOrder();
        $worker = $this->makeWorker($tenant, $branch, $workshop, ['hourly_rate' => '40.0000']);

        $job = $this->postJson("/api/v1/app/work-orders/{$woId}/jobs", [
            'description' => 'Replace brake pads', 'estimated_hours' => 2.5,
        ], $headers)->assertStatus(201);
        $jobId = $job->json('data.id');

        $this->postJson("/api/v1/app/work-orders/{$woId}/mechanics", [
            'worker_id' => $worker->id, 'maintenance_job_id' => $jobId,
        ], $headers)->assertStatus(201);

        $show = $this->getJson("/api/v1/app/work-orders/{$woId}", $headers)->assertOk();
        $jobs = $show->json('data.jobs');
        $this->assertSame('100.0000', $jobs[0]['estimated_labor_cost_computed']); // 2.50 * 40.0000 = 100.0000
    }

    public function test_job_computed_labor_cost_is_null_without_a_mechanic_or_estimate(): void
    {
        [, , , , $woId, $headers] = $this->setUpWorkOrder();

        $this->postJson("/api/v1/app/work-orders/{$woId}/jobs", [
            'description' => 'Unassigned job', 'estimated_hours' => 3,
        ], $headers)->assertStatus(201);

        $show = $this->getJson("/api/v1/app/work-orders/{$woId}", $headers)->assertOk();
        $this->assertNull($show->json('data.jobs.0.estimated_labor_cost_computed'));
    }

    public function test_work_order_level_estimate_aggregates_across_multiple_jobs(): void
    {
        [$tenant, $branch, $workshop, , $woId, $headers] = $this->setUpWorkOrder();
        $workerA = $this->makeWorker($tenant, $branch, $workshop, ['hourly_rate' => '30.0000']);
        $workerB = $this->makeWorker($tenant, $branch, $workshop, ['hourly_rate' => '45.0000']);

        $jobA = $this->postJson("/api/v1/app/work-orders/{$woId}/jobs", [
            'description' => 'Job A', 'estimated_hours' => 2,
        ], $headers)->assertStatus(201)->json('data.id');
        $jobB = $this->postJson("/api/v1/app/work-orders/{$woId}/jobs", [
            'description' => 'Job B', 'estimated_hours' => 4,
        ], $headers)->assertStatus(201)->json('data.id');

        $this->postJson("/api/v1/app/work-orders/{$woId}/mechanics", ['worker_id' => $workerA->id, 'maintenance_job_id' => $jobA], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/work-orders/{$woId}/mechanics", ['worker_id' => $workerB->id, 'maintenance_job_id' => $jobB], $headers)->assertStatus(201);

        // Job A: 2 * 30 = 60.0000, Job B: 4 * 45 = 180.0000, total = 240.0000
        $show = $this->getJson("/api/v1/app/work-orders/{$woId}", $headers)->assertOk();
        $this->assertSame('240.0000', $show->json('data.estimated_labor_cost_computed'));
    }

    public function test_computed_estimate_is_independent_of_the_manual_estimate_field(): void
    {
        [$tenant, $branch, $workshop, , $woId, $headers] = $this->setUpWorkOrder();
        $worker = $this->makeWorker($tenant, $branch, $workshop, ['hourly_rate' => '40.0000']);
        $jobId = $this->postJson("/api/v1/app/work-orders/{$woId}/jobs", [
            'description' => 'Job', 'estimated_hours' => 2,
        ], $headers)->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/work-orders/{$woId}/mechanics", ['worker_id' => $worker->id, 'maintenance_job_id' => $jobId], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/work-orders/{$woId}/estimate", [
            'estimated_labor_cost' => '999.0000',
        ], $headers)->assertOk();

        $show = $this->getJson("/api/v1/app/work-orders/{$woId}", $headers)->assertOk();
        $this->assertSame('999.0000', $show->json('data.estimated_labor_cost'), 'Manual entry must remain untouched.');
        $this->assertSame('80.0000', $show->json('data.estimated_labor_cost_computed'), 'Computed suggestion must not be overwritten by the manual figure.');
    }

    public function test_unassigning_a_mechanic_removes_it_from_the_computed_estimate(): void
    {
        [$tenant, $branch, $workshop, , $woId, $headers] = $this->setUpWorkOrder();
        $worker = $this->makeWorker($tenant, $branch, $workshop, ['hourly_rate' => '40.0000']);
        $jobId = $this->postJson("/api/v1/app/work-orders/{$woId}/jobs", [
            'description' => 'Job', 'estimated_hours' => 2,
        ], $headers)->assertStatus(201)->json('data.id');
        $assignmentId = $this->postJson("/api/v1/app/work-orders/{$woId}/mechanics", [
            'worker_id' => $worker->id, 'maintenance_job_id' => $jobId,
        ], $headers)->assertStatus(201)->json('data.id');

        $this->postJson("/api/v1/app/work-orders/{$woId}/mechanics/{$assignmentId}/unassign", [], $headers)->assertOk();

        $show = $this->getJson("/api/v1/app/work-orders/{$woId}", $headers)->assertOk();
        $this->assertNull($show->json('data.jobs.0.estimated_labor_cost_computed'));
    }
}
