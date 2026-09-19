<?php

namespace Tests\Feature;

use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Enhancement OptiFleet MR/WO — EXTERNAL: a top-level Work Order status
 * parallel to IN_PROGRESS (work carried out by an external workshop),
 * distinct from the separate WorkOrderExternalService towing/3rd-party
 * invoicing sub-resource, which is untouched by this feature.
 */
class WorkOrderExternalStatusTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'WOX-'.Str::random(4)]);
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

    private function createScheduledWorkOrder($workshop, $vehicle, $headers): string
    {
        $create = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');

        $this->postJson("/api/v1/app/work-orders/{$id}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/assign", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/schedule", [], $headers)->assertOk()->assertJsonPath('data.status', 'SCHEDULED');

        return $id;
    }

    public function test_scheduled_work_order_can_be_sent_external(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createScheduledWorkOrder($workshop, $vehicle, $headers);

        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'EXTERNAL');
    }

    public function test_in_progress_work_order_can_be_sent_external(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createScheduledWorkOrder($workshop, $vehicle, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id}/start", [], $headers)->assertOk();

        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'EXTERNAL');
    }

    public function test_external_work_order_can_resume_in_house(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createScheduledWorkOrder($workshop, $vehicle, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();

        $this->postJson("/api/v1/app/work-orders/{$id}/resume", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
    }

    public function test_external_work_order_can_go_straight_to_qc(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createScheduledWorkOrder($workshop, $vehicle, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();

        $this->postJson("/api/v1/app/work-orders/{$id}/submit-to-qc", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'QC_PENDING');
        $this->postJson("/api/v1/app/work-orders/{$id}/complete", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    }

    public function test_external_work_order_can_be_cancelled(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createScheduledWorkOrder($workshop, $vehicle, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();

        $this->postJson("/api/v1/app/work-orders/{$id}/cancel", [], $headers)
            ->assertOk()->assertJsonPath('data.status', 'CANCELLED');
    }

    public function test_draft_work_order_cannot_go_directly_external(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/work-orders/{$create->json('data.id')}/external", [], $headers)->assertStatus(422);
    }

    public function test_external_work_order_cannot_jump_to_assigned(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createScheduledWorkOrder($workshop, $vehicle, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();

        $this->postJson("/api/v1/app/work-orders/{$id}/assign", [], $headers)->assertStatus(422);
    }

    public function test_sending_external_requires_permission(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $fullToken] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $id = $this->createScheduledWorkOrder($workshop, $vehicle, $this->authHeaders($fullToken));

        $permissionsWithoutPause = array_values(array_diff($this->fullPermissions(), ['work_order.pause']));
        [, $limitedToken] = $this->makeTenantUser($tenant, $permissionsWithoutPause);

        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $this->authHeaders($limitedToken))->assertStatus(403);
    }

    public function test_vehicle_release_is_blocked_while_another_work_order_is_external(): void
    {
        [$tenant, $branch, $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, array_merge($this->fullPermissions(), ['vehicle_release.perform']));
        $headers = $this->authHeaders($token);

        // First WO: driven all the way to COMPLETED so release() itself is reachable.
        $id1 = $this->createScheduledWorkOrder($workshop, $vehicle, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id1}/start", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id1}/submit-to-qc", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id1}/complete", [], $headers)->assertOk();

        // Second WO for the same vehicle is out at an external workshop — still active.
        $id2 = $this->createScheduledWorkOrder($workshop, $vehicle, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id2}/external", [], $headers)->assertOk();

        $this->postJson("/api/v1/app/work-orders/{$id1}/release", [], $headers)->assertStatus(422);
    }

    public function test_work_order_print_still_works_while_external(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createScheduledWorkOrder($workshop, $vehicle, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();

        $response = $this->get("/api/v1/app/work-orders/{$id}/print", $headers);
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }
}
