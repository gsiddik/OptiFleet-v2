<?php

namespace Tests\Feature;

use App\Domain\MaintenancePolicy\Models\MaintenancePackage;
use App\Domain\MaintenancePolicy\Models\MaintenanceSchedule;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase G — Carried-Forward VMS Parity: G-01 (Schedule -> Work Order
 * conversion), G-02 (Cost Estimation), G-03 (Maintenance Result), G-04
 * (unresolved findings/QC findings block closure).
 */
class WorkOrderLifecycleGapsTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'WOG-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'MAINTENANCE');
        $this->grantModule($tenant, 'WORKSHOP');
        $this->grantModule($tenant, 'WORK_ORDER');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id, 'current_odometer' => 50000]);

        return [$tenant, $branch, $workshop, $vehicle];
    }

    private function fullPermissions(): array
    {
        return [
            'work_order.view', 'work_order.create', 'work_order.update', 'work_order.submit',
            'work_order.approve', 'work_order.assign', 'work_order.schedule', 'work_order.start',
            'work_order.pause', 'work_order.complete', 'work_order.close', 'work_order.cancel', 'work_order.estimate',
            'maintenance_schedule.view', 'maintenance_schedule.convert_work_order',
            'diagnosis.manage', 'qc.view', 'qc.perform', 'qc.approve', 'qc.reject',
        ];
    }

    private function driveToInProgress(string $woId, array $headers): void
    {
        $this->postJson("/api/v1/app/work-orders/{$woId}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$woId}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$woId}/assign", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$woId}/schedule", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$woId}/start", [], $headers)->assertOk();
    }

    private function driveToQcPending(string $woId, array $headers): void
    {
        $this->driveToInProgress($woId, $headers);
        $this->postJson("/api/v1/app/work-orders/{$woId}/submit-to-qc", [], $headers)->assertOk();
    }

    // ---- G-01: Schedule -> Work Order conversion -------------------------------------------

    public function test_due_schedule_converts_to_work_order(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $package = MaintenancePackage::query()->create(['tenant_id' => $tenant->id, 'code' => 'PM-G01', 'name' => 'Oil Service', 'maintenance_type' => 'PREVENTIVE', 'status' => 'ACTIVE']);
        $schedule = MaintenanceSchedule::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'maintenance_package_id' => $package->id,
            'next_due_odometer' => 50000, 'tolerance_odometer' => 500, 'status' => 'DUE',
        ]);

        $response = $this->postJson("/api/v1/app/maintenance-schedules/{$schedule->id}/work-order", [
            'workshop_id' => $workshop->id,
        ], $headers)->assertStatus(201);

        $this->assertSame($schedule->id, $response->json('data.maintenance_schedule_id'));
        $this->assertSame('PREVENTIVE', $response->json('data.maintenance_type'));
    }

    public function test_upcoming_schedule_cannot_convert_yet(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $package = MaintenancePackage::query()->create(['tenant_id' => $tenant->id, 'code' => 'PM-UPC', 'name' => 'Future Service', 'maintenance_type' => 'PREVENTIVE', 'status' => 'ACTIVE']);
        $schedule = MaintenanceSchedule::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'maintenance_package_id' => $package->id,
            'next_due_odometer' => 80000, 'tolerance_odometer' => 500, 'status' => 'UPCOMING',
        ]);

        $this->postJson("/api/v1/app/maintenance-schedules/{$schedule->id}/work-order", ['workshop_id' => $workshop->id], $headers)->assertStatus(422);
    }

    public function test_schedule_cannot_be_converted_twice_while_open(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $package = MaintenancePackage::query()->create(['tenant_id' => $tenant->id, 'code' => 'PM-DBL', 'name' => 'Dup Service', 'maintenance_type' => 'PREVENTIVE', 'status' => 'ACTIVE']);
        $schedule = MaintenanceSchedule::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'maintenance_package_id' => $package->id,
            'next_due_odometer' => 50000, 'tolerance_odometer' => 500, 'status' => 'DUE',
        ]);

        $this->postJson("/api/v1/app/maintenance-schedules/{$schedule->id}/work-order", ['workshop_id' => $workshop->id], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/maintenance-schedules/{$schedule->id}/work-order", ['workshop_id' => $workshop->id], $headers)->assertStatus(422);
    }

    public function test_completing_a_schedule_derived_work_order_marks_schedule_completed_and_regenerates(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $package = MaintenancePackage::query()->create(['tenant_id' => $tenant->id, 'code' => 'PM-COMP', 'name' => 'Completed Service', 'maintenance_type' => 'PREVENTIVE', 'status' => 'ACTIVE']);
        $package->intervals()->create(['trigger_type' => 'ODOMETER', 'odometer_km' => 10000, 'tolerance_km' => 500]);
        $schedule = MaintenanceSchedule::query()->create([
            'tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'maintenance_package_id' => $package->id,
            'next_due_odometer' => 50000, 'tolerance_odometer' => 500, 'status' => 'DUE',
        ]);

        $create = $this->postJson("/api/v1/app/maintenance-schedules/{$schedule->id}/work-order", ['workshop_id' => $workshop->id], $headers)->assertStatus(201);
        $woId = $create->json('data.id');

        $this->driveToQcPending($woId, $headers);
        $this->postJson("/api/v1/app/work-orders/{$woId}/complete", [], $headers)->assertOk();

        $schedule->refresh();
        // markCompleted() stamps completion then immediately regenerates the next cycle
        // (10,000km further out), so the row settles into whatever status that recomputed
        // due date represents — here, far enough away to be UPCOMING again — rather than
        // staying literally "COMPLETED" forever.
        $this->assertSame($woId, $schedule->last_completed_work_order_id);
        $this->assertNotNull($schedule->last_completed_at);
        $this->assertSame(60000, $schedule->next_due_odometer);
        $this->assertSame('UPCOMING', $schedule->status);
    }

    // ---- G-02: Cost Estimation ---------------------------------------------------------------

    public function test_estimate_computes_decimal_safe_total(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');

        $response = $this->postJson("/api/v1/app/work-orders/{$id}/estimate", [
            'estimated_labor_cost' => '150000.3333', 'estimated_parts_cost' => '75000.3334',
        ], $headers)->assertOk();

        $this->assertSame('225000.6667', $response->json('data.estimated_total_cost'));
    }

    public function test_estimate_is_rejected_once_work_order_is_in_progress(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');
        $this->driveToInProgress($id, $headers);

        $this->postJson("/api/v1/app/work-orders/{$id}/estimate", ['estimated_labor_cost' => '10000'], $headers)->assertStatus(422);
    }

    // ---- G-03: Maintenance Result -------------------------------------------------------------

    public function test_completing_with_a_result_summary_persists_it(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');
        $this->driveToQcPending($id, $headers);

        $response = $this->postJson("/api/v1/app/work-orders/{$id}/complete", [
            'result_summary' => 'Replaced brake pads front axle; test drive OK.',
        ], $headers)->assertOk();

        $this->assertSame('Replaced brake pads front axle; test drive OK.', $response->json('data.result_summary'));
        $this->assertNotNull($response->json('data.result_recorded_at'));
    }

    public function test_completing_without_a_result_summary_still_succeeds(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');
        $this->driveToQcPending($id, $headers);

        $this->postJson("/api/v1/app/work-orders/{$id}/complete", [], $headers)->assertOk()->assertJsonPath('data.result_summary', null);
    }

    // ---- G-04: unresolved findings/QC findings block closure -----------------------------------

    public function test_open_work_order_finding_blocks_completion_until_resolved(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');

        // Findings are Draft-only — add before driving the WO forward.
        $finding = $this->postJson("/api/v1/app/work-orders/{$id}/findings", [
            'severity' => 'HIGH', 'description' => 'Worn fan belt found during inspection.',
        ], $headers)->assertStatus(201);
        $findingId = $finding->json('data.id');
        $this->assertSame('OPEN', $finding->json('data.status'));

        $this->driveToInProgress($id, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id}/submit-to-qc", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/complete", [], $headers)->assertStatus(422);

        $this->postJson("/api/v1/app/work-orders/{$id}/findings/{$findingId}/resolve", [
            'resolution_notes' => 'Fan belt replaced.',
        ], $headers)->assertOk()->assertJsonPath('data.status', 'RESOLVED');

        $this->postJson("/api/v1/app/work-orders/{$id}/complete", [], $headers)->assertOk();
    }

    public function test_resolving_an_already_resolved_finding_is_rejected(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');

        $finding = $this->postJson("/api/v1/app/work-orders/{$id}/findings", [
            'severity' => 'LOW', 'description' => 'Minor scratch.',
        ], $headers)->assertStatus(201);
        $findingId = $finding->json('data.id');

        $this->driveToInProgress($id, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id}/findings/{$findingId}/resolve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/findings/{$findingId}/resolve", [], $headers)->assertStatus(422);
    }

    public function test_unresolved_qc_finding_blocks_completion_until_resolved(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');
        $this->driveToQcPending($id, $headers);

        $start = $this->postJson("/api/v1/app/work-orders/{$id}/qc/start", [], $headers)->assertStatus(201);
        $inspectionId = $start->json('data.id');
        $findingResponse = $this->postJson("/api/v1/app/work-orders/{$id}/qc/{$inspectionId}/findings", [
            'description' => 'Loose bolt on skid plate.', 'severity' => 'MEDIUM',
        ], $headers)->assertStatus(201);
        $qcFindingId = $findingResponse->json('data.id');
        $this->assertFalse($findingResponse->json('data.resolved'));

        $this->postJson("/api/v1/app/work-orders/{$id}/qc/{$inspectionId}/pass", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/qc/{$inspectionId}/complete", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/complete", [], $headers)->assertStatus(422);

        $this->postJson("/api/v1/app/work-orders/{$id}/qc/{$inspectionId}/findings/{$qcFindingId}/resolve", [], $headers)
            ->assertOk()->assertJsonPath('data.resolved', true);

        $this->postJson("/api/v1/app/work-orders/{$id}/complete", [], $headers)->assertOk();
    }

    public function test_already_resolved_qc_finding_cannot_be_resolved_again(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');
        $this->driveToQcPending($id, $headers);

        $start = $this->postJson("/api/v1/app/work-orders/{$id}/qc/start", [], $headers)->assertStatus(201);
        $inspectionId = $start->json('data.id');
        $findingResponse = $this->postJson("/api/v1/app/work-orders/{$id}/qc/{$inspectionId}/findings", [
            'description' => 'Test finding.', 'severity' => 'LOW',
        ], $headers)->assertStatus(201);
        $qcFindingId = $findingResponse->json('data.id');

        $this->postJson("/api/v1/app/work-orders/{$id}/qc/{$inspectionId}/findings/{$qcFindingId}/resolve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/qc/{$inspectionId}/findings/{$qcFindingId}/resolve", [], $headers)->assertStatus(422);
    }

    public function test_cross_tenant_work_order_cannot_have_its_finding_resolved(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');
        $finding = $this->postJson("/api/v1/app/work-orders/{$id}/findings", [
            'severity' => 'LOW', 'description' => 'x',
        ], $headers)->assertStatus(201);
        $this->driveToInProgress($id, $headers);

        [$otherTenant, , $otherWorkshop, $otherVehicle] = $this->setUpTenant();
        [, $otherToken] = $this->makeTenantUser($otherTenant, $this->fullPermissions());
        $otherHeaders = $this->authHeaders($otherToken);
        $otherCreate = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $otherVehicle->id, 'workshop_id' => $otherWorkshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $otherHeaders)->assertStatus(201);
        $otherId = $otherCreate->json('data.id');

        // The other tenant's WO does not own this finding — mismatched pair must 404.
        $this->postJson("/api/v1/app/work-orders/{$otherId}/findings/{$finding->json('data.id')}/resolve", [], $otherHeaders)->assertStatus(404);
    }
}
