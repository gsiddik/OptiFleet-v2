<?php

namespace Tests\Feature;

use App\Domain\Configuration\Models\ConfigurationVersion;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\Workflow\Services\WorkflowDefinitionService;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Section 66: proves the migration/integration itself, beyond "nothing
 * regressed" — that a tenant-published workflow override actually changes
 * live behavior (Section 21's configurability requirement), and that an
 * already-in-flight resource keeps the workflow version it was created
 * under even after the tenant republishes (Section 25).
 */
class WorkflowMigrationTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'WFM-'.Str::random(4)]);
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

    public function test_maintenance_request_uses_migrated_platform_default_workflow(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_request.view', 'maintenance_request.create', 'maintenance_request.review']);

        $id = $this->postJson('/api/v1/app/maintenance-requests', [
            'vehicle_id' => $vehicle->id, 'priority' => 'MEDIUM', 'complaint' => 'Brake noise',
        ], $this->authHeaders($token))->assertStatus(201)->json('data.id');

        $request = MaintenanceRequest::query()->findOrFail($id);
        $this->assertNotNull($request->workflow_configuration_version_id);
        $this->assertTrue(ConfigurationVersion::query()->find($request->workflow_configuration_version_id)->configurationSet->is_system);

        // The exact legacy graph still works end to end via the engine.
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/submit", [], $this->authHeaders($token))->assertOk();

        // And an invalid jump (DRAFT-only successor skipped) is still rejected.
        $illegal = $this->postJson("/api/v1/app/maintenance-requests/{$id}/submit", [], $this->authHeaders($token));
        $illegal->assertStatus(422);
    }

    public function test_tenant_workflow_override_changes_live_behavior_without_affecting_other_tenants(): void
    {
        [$tenantA] = $this->setUpTenant();
        [$tenantB, , , $vehicleB] = $this->setUpTenant();

        // Tenant A publishes a stricter override: DRAFT can no longer go straight to CANCELLED.
        $definitions = app(WorkflowDefinitionService::class);
        $set = $definitions->findOrCreateSet($tenantA->id, 'maintenance_request', 'TENANT', null, 'Tenant A Override');
        $definitions->publish($definitions->createDraft($set, [
            'statuses' => [
                ['code' => 'DRAFT', 'display_name' => 'Draft', 'is_start' => true],
                ['code' => 'SUBMITTED', 'display_name' => 'Submitted'],
            ],
            'transitions' => [
                ['from_status' => 'DRAFT', 'to_status' => 'SUBMITTED', 'action_code' => 'submit', 'action_label' => 'Submit'],
                // no DRAFT -> CANCELLED here, unlike the platform default
            ],
        ], null), null);

        $engine = app(WorkflowEngine::class);
        $versionA = $engine->resolveEffective('maintenance_request', $tenantA->id);
        $versionB = $engine->resolveEffective('maintenance_request', $tenantB->id);

        $this->assertFalse($engine->isTransitionAllowedForVersion($versionA, 'DRAFT', 'CANCELLED'));
        $this->assertTrue($engine->isTransitionAllowedForVersion($versionB, 'DRAFT', 'CANCELLED')); // tenant B untouched, still on platform default
    }

    public function test_in_flight_work_order_keeps_its_pinned_workflow_version_after_republish(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['work_order.view', 'work_order.create', 'work_order.submit']);

        $id = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ], $this->authHeaders($token))->assertStatus(201)->json('data.id');

        $workOrder = WorkOrder::query()->findOrFail($id);
        $pinnedVersionId = $workOrder->workflow_configuration_version_id;
        $this->assertNotNull($pinnedVersionId);

        // Tenant now publishes their OWN override that removes DRAFT -> SUBMITTED entirely.
        $definitions = app(WorkflowDefinitionService::class);
        $set = $definitions->findOrCreateSet($tenant->id, 'work_order', 'TENANT', null, 'Tenant WO Override');
        $definitions->publish($definitions->createDraft($set, [
            'statuses' => [
                ['code' => 'DRAFT', 'display_name' => 'Draft', 'is_start' => true],
                ['code' => 'CANCELLED', 'display_name' => 'Cancelled'],
            ],
            'transitions' => [
                ['from_status' => 'DRAFT', 'to_status' => 'CANCELLED', 'action_code' => 'cancel', 'action_label' => 'Cancel'],
            ],
        ], null), null);

        // The already-created WO is still validated against the version it was pinned to at
        // creation (which allows DRAFT -> SUBMITTED) — NOT the tenant's brand-new override.
        $this->postJson("/api/v1/app/work-orders/{$id}/submit", [], $this->authHeaders($token))->assertOk();

        $this->assertSame($pinnedVersionId, $workOrder->fresh()->workflow_configuration_version_id);
    }

    public function test_simulation_against_a_real_resource_reports_tenant_configured_approval_requirement(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [$reviewer] = $this->makeTenantUser($tenant, ['maintenance_request.approve']);
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_request.view', 'maintenance_request.create']);

        $id = $this->postJson('/api/v1/app/maintenance-requests', [
            'vehicle_id' => $vehicle->id, 'priority' => 'HIGH', 'complaint' => 'Engine warning light',
        ], $this->authHeaders($token))->assertStatus(201)->json('data.id');
        $request = MaintenanceRequest::query()->findOrFail($id);

        // Tenant configures an approval requirement on UNDER_REVIEW -> APPROVED.
        $definitions = app(WorkflowDefinitionService::class);
        $set = $definitions->findOrCreateSet($tenant->id, 'maintenance_request', 'TENANT', null, 'Tenant Approval Override');
        $version = $definitions->publish($definitions->createDraft($set, [
            'statuses' => [
                ['code' => 'DRAFT', 'display_name' => 'Draft', 'is_start' => true],
                ['code' => 'UNDER_REVIEW', 'display_name' => 'Under Review'],
                ['code' => 'APPROVED', 'display_name' => 'Approved'],
            ],
            'transitions' => [
                ['from_status' => 'DRAFT', 'to_status' => 'UNDER_REVIEW', 'action_code' => 'review', 'action_label' => 'Review'],
                [
                    'from_status' => 'UNDER_REVIEW', 'to_status' => 'APPROVED', 'action_code' => 'approve', 'action_label' => 'Approve',
                    'approval_rule' => ['type' => 'SINGLE', 'steps' => [['step_number' => 1, 'approver_type' => 'PERMISSION', 'approver_identifier' => 'maintenance_request.approve']]],
                ],
            ],
        ], null), null);

        $engine = app(WorkflowEngine::class);
        $simulation = $engine->simulate($version, 'UNDER_REVIEW', $reviewer, $tenant->id, ['request_id' => $request->id, 'vehicle_id' => $request->vehicle_id]);

        $this->assertCount(1, $simulation);
        $this->assertTrue($simulation[0]['requires_approval']);
        $this->assertSame('SINGLE', $simulation[0]['approval_rule']['type']);
    }
}
