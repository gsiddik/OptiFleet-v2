<?php

namespace Tests\Feature;

use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderExternalReference;
use App\Domain\WorkOrder\Models\WorkOrderFinding;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Consolidated External Workshop business rules: an External Work Order
 * uses only Findings as its scope of work, is finalized only from DRAFT,
 * and exits only via Revise (-> DRAFT) or Cancel (-> CANCELLED). No
 * External Workshop login/portal/role/permission/public URL exists at
 * all — every action here is performed by an authorized in-house user.
 */
class ExternalWorkOrderTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'EWO-'.Str::random(4)]);
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

    private function externalPermissions(): array
    {
        return [
            'work_order.view', 'work_order.create', 'work_order.cancel',
            'work_order.prepare_external', 'work_order.finalize_external', 'work_order.revise_external',
            'work_order.cancel_external', 'work_order.view_workshop_invoice_reference',
        ];
    }

    private function createDraftWorkOrder($workshop, $vehicle, $headers): string
    {
        return $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201)->json('data.id');
    }

    private function markExternalAndAddFinding(string $id, $headers): void
    {
        $this->postJson("/api/v1/app/work-orders/{$id}/execution-mode/external", [], $headers)->assertOk()->assertJsonPath('data.execution_mode', 'EXTERNAL');
        $this->postJson("/api/v1/app/work-orders/{$id}/external-findings", [
            'severity' => 'HIGH', 'description' => 'Cracked cylinder head.',
        ], $headers)->assertStatus(201);
    }

    // --- Draft preparation ---------------------------------------------------

    public function test_findings_can_be_added_edited_and_deleted_in_draft_external_mode(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createDraftWorkOrder($workshop, $vehicle, $headers);
        $this->markExternalAndAddFinding($id, $headers);

        $findingId = WorkOrderFinding::query()->where('work_order_id', $id)->firstOrFail()->id;

        $this->putJson("/api/v1/app/work-orders/{$id}/external-findings/{$findingId}", [
            'severity' => 'CRITICAL', 'description' => 'Cracked cylinder head — worse than first noted.',
        ], $headers)->assertOk()->assertJsonPath('data.severity', 'CRITICAL');

        $this->deleteJson("/api/v1/app/work-orders/{$id}/external-findings/{$findingId}", [], $headers)->assertOk();
        $this->assertSame(0, WorkOrderFinding::query()->where('work_order_id', $id)->count());
    }

    public function test_internal_workshop_capabilities_are_rejected_for_draft_external_mode(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, array_merge($this->externalPermissions(), ['diagnosis.manage']));
        $headers = $this->authHeaders($token);
        $id = $this->createDraftWorkOrder($workshop, $vehicle, $headers);
        $this->markExternalAndAddFinding($id, $headers);

        // Diagnosis is an internal-execution capability gated by EXECUTABLE_STATUSES, which never
        // includes DRAFT for either regular or External-mode Work Orders.
        $this->postJson("/api/v1/app/work-orders/{$id}/diagnoses", [
            'description' => 'internal diagnosis attempt',
        ], $headers)->assertStatus(422);
    }

    public function test_external_workshop_action_is_rejected_without_a_finding(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createDraftWorkOrder($workshop, $vehicle, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id}/execution-mode/external", [], $headers)->assertOk();

        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertStatus(422);
        $this->assertSame('DRAFT', WorkOrder::query()->findOrFail($id)->status);
        $this->assertSame(0, WorkOrderExternalReference::query()->where('work_order_id', $id)->count());
    }

    // --- Initial finalization -------------------------------------------------

    public function test_initial_finalization_creates_revision_one_and_exactly_one_reference(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createDraftWorkOrder($workshop, $vehicle, $headers);
        $this->markExternalAndAddFinding($id, $headers);

        $response = $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();
        $this->assertSame('EXTERNAL', $response->json('data.status'));
        $this->assertSame(1, $response->json('data.external_finalized_revision'));
        $this->assertSame(1, WorkOrderExternalReference::query()->where('work_order_id', $id)->count());
    }

    public function test_repeated_finalization_requests_are_idempotent(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createDraftWorkOrder($workshop, $vehicle, $headers);
        $this->markExternalAndAddFinding($id, $headers);

        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();
        $second = $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();

        $this->assertSame(1, $second->json('data.external_finalized_revision'), 'A repeated finalize call must not double-increment.');
        $this->assertSame(1, WorkOrderExternalReference::query()->where('work_order_id', $id)->count());
    }

    public function test_finalizing_without_execution_mode_set_is_rejected(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createDraftWorkOrder($workshop, $vehicle, $headers);

        // Never called execution-mode/external — this is a regular internal Draft.
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertStatus(422);
    }

    // --- External status: read-only, mutation rejected -------------------------

    public function test_external_status_rejects_findings_mutation(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createDraftWorkOrder($workshop, $vehicle, $headers);
        $this->markExternalAndAddFinding($id, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();

        $findingId = WorkOrderFinding::query()->where('work_order_id', $id)->firstOrFail()->id;
        $this->postJson("/api/v1/app/work-orders/{$id}/external-findings", ['severity' => 'LOW', 'description' => 'x'], $headers)->assertStatus(422);
        $this->putJson("/api/v1/app/work-orders/{$id}/external-findings/{$findingId}", ['description' => 'tampering'], $headers)->assertStatus(422);
        $this->deleteJson("/api/v1/app/work-orders/{$id}/external-findings/{$findingId}", [], $headers)->assertStatus(422);
    }

    public function test_external_status_rejects_every_internal_transition(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, array_merge($this->externalPermissions(), [
            'work_order.submit', 'work_order.approve', 'work_order.assign', 'work_order.schedule', 'work_order.start',
            'work_order.pause', 'work_order.complete', 'work_order.close',
        ]));
        $headers = $this->authHeaders($token);
        $id = $this->createDraftWorkOrder($workshop, $vehicle, $headers);
        $this->markExternalAndAddFinding($id, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();

        foreach (['submit', 'approve', 'assign', 'schedule', 'start', 'hold', 'resume', 'wait-for-part', 'submit-to-qc', 'complete', 'close'] as $action) {
            $this->postJson("/api/v1/app/work-orders/{$id}/{$action}", [], $headers)->assertStatus(422);
        }
        // Generic cancel is explicitly disallowed for EXTERNAL — the dedicated reason-required action is required instead.
        $this->postJson("/api/v1/app/work-orders/{$id}/cancel", [], $headers)->assertStatus(422);
    }

    public function test_print_is_unaffected_by_external_status(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createDraftWorkOrder($workshop, $vehicle, $headers);
        $this->markExternalAndAddFinding($id, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();

        $response = $this->get("/api/v1/app/work-orders/{$id}/print", $headers);
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    // --- Revise ----------------------------------------------------------------

    public function test_revise_returns_to_draft_and_retains_mode_number_and_findings(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createDraftWorkOrder($workshop, $vehicle, $headers);
        $this->markExternalAndAddFinding($id, $headers);
        $woNumber = WorkOrder::query()->findOrFail($id)->wo_number;
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();

        $response = $this->postJson("/api/v1/app/work-orders/{$id}/external/revise", [], $headers)->assertOk();
        $this->assertSame('DRAFT', $response->json('data.status'));
        $this->assertSame('EXTERNAL', $response->json('data.execution_mode'));
        $this->assertSame($woNumber, $response->json('data.wo_number'));
        $this->assertSame(1, $response->json('data.external_finalized_revision'), 'Revise must not change the last finalized revision number.');
        $this->assertSame(1, WorkOrderFinding::query()->where('work_order_id', $id)->count());
        $this->assertSame(1, WorkOrderExternalReference::query()->where('work_order_id', $id)->count(), 'Revise must reuse, not duplicate, the reference.');
    }

    public function test_revised_work_order_reference_is_not_active_while_under_revision(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createDraftWorkOrder($workshop, $vehicle, $headers);
        $this->markExternalAndAddFinding($id, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/external/revise", [], $headers)->assertOk();

        $active = $this->getJson('/api/v1/app/external-work-order-references', $headers)->assertOk()->json('data');
        $this->assertEmpty(array_filter($active, fn ($row) => $row['work_order_id'] === $id));
    }

    public function test_revise_is_only_valid_from_external_status(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createDraftWorkOrder($workshop, $vehicle, $headers);
        $this->markExternalAndAddFinding($id, $headers);

        $this->postJson("/api/v1/app/work-orders/{$id}/external/revise", [], $headers)->assertStatus(422);
    }

    // --- Re-finalization ---------------------------------------------------

    public function test_re_finalization_requires_a_finding_and_increments_revision_exactly_once(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createDraftWorkOrder($workshop, $vehicle, $headers);
        $this->markExternalAndAddFinding($id, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/external/revise", [], $headers)->assertOk();

        $findingId = WorkOrderFinding::query()->where('work_order_id', $id)->firstOrFail()->id;
        $this->deleteJson("/api/v1/app/work-orders/{$id}/external-findings/{$findingId}", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertStatus(422);

        $this->postJson("/api/v1/app/work-orders/{$id}/external-findings", [
            'severity' => 'LOW', 'description' => 'Revised finding.',
        ], $headers)->assertStatus(201);

        $response = $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();
        $this->assertSame(2, $response->json('data.external_finalized_revision'));

        $active = $this->getJson('/api/v1/app/external-work-order-references', $headers)->assertOk()->json('data');
        $this->assertNotEmpty(array_filter($active, fn ($row) => $row['work_order_id'] === $id && $row['revision'] === 2));
        $this->assertSame(1, WorkOrderExternalReference::query()->where('work_order_id', $id)->count(), 'Re-finalization must reuse, not duplicate, the reference.');
    }

    // --- Cancel ----------------------------------------------------------------

    public function test_cancel_requires_a_reason(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createDraftWorkOrder($workshop, $vehicle, $headers);
        $this->markExternalAndAddFinding($id, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();

        $this->postJson("/api/v1/app/work-orders/{$id}/external/cancel", [], $headers)->assertStatus(422);
    }

    public function test_cancel_preserves_data_and_removes_reference_from_active_list(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createDraftWorkOrder($workshop, $vehicle, $headers);
        $this->markExternalAndAddFinding($id, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();

        $response = $this->postJson("/api/v1/app/work-orders/{$id}/external/cancel", ['reason' => 'Vehicle no longer needs this repair.'], $headers)->assertOk();
        $this->assertSame('CANCELLED', $response->json('data.status'));
        $this->assertSame('Vehicle no longer needs this repair.', $response->json('data.cancellation_reason'));

        $this->assertSame(1, WorkOrderFinding::query()->where('work_order_id', $id)->count(), 'Cancel must not delete Findings.');
        $this->assertSame(1, WorkOrderExternalReference::query()->where('work_order_id', $id)->count(), 'Cancel must not delete the reference.');

        $active = $this->getJson('/api/v1/app/external-work-order-references', $headers)->assertOk()->json('data');
        $this->assertEmpty(array_filter($active, fn ($row) => $row['work_order_id'] === $id));
    }

    public function test_repeated_cancel_is_rejected_not_silently_reapplied(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->createDraftWorkOrder($workshop, $vehicle, $headers);
        $this->markExternalAndAddFinding($id, $headers);
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/external/cancel", ['reason' => 'first'], $headers)->assertOk();

        $this->postJson("/api/v1/app/work-orders/{$id}/external/cancel", ['reason' => 'second'], $headers)->assertStatus(422);
    }

    // --- Security ---------------------------------------------------------------

    public function test_finalize_requires_permission(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $fullToken] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $id = $this->createDraftWorkOrder($workshop, $vehicle, $this->authHeaders($fullToken));
        $this->markExternalAndAddFinding($id, $this->authHeaders($fullToken));

        $limited = array_values(array_diff($this->externalPermissions(), ['work_order.finalize_external']));
        [, $limitedToken] = $this->makeTenantUser($tenant, $limited);

        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $this->authHeaders($limitedToken))->assertStatus(403);
    }

    public function test_cross_tenant_work_order_cannot_be_finalized(): void
    {
        [$tenantA, , $workshopA, $vehicleA] = $this->setUpTenant();
        [, $tokenA] = $this->makeTenantUser($tenantA, $this->externalPermissions());
        $id = $this->createDraftWorkOrder($workshopA, $vehicleA, $this->authHeaders($tokenA));
        $this->markExternalAndAddFinding($id, $this->authHeaders($tokenA));

        [$tenantB] = $this->setUpTenant();
        [, $tokenB] = $this->makeTenantUser($tenantB, $this->externalPermissions());

        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $this->authHeaders($tokenB))->assertStatus(404);
    }

    public function test_reference_list_is_branch_scoped(): void
    {
        [$tenant, $branchA, $workshopA, $vehicleA] = $this->setUpTenant();
        $branchB = $this->makeBranch($tenant);
        $workshopB = $this->makeWorkshop($tenant, $branchB);
        $category = $this->makeVehicleCategory();
        $vehicleB = $this->makeVehicle($tenant, $branchB, $category, ['default_workshop_id' => $workshopB->id, 'registration_number' => 'B-SCOPE-EWO']);

        [, $fullToken] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $fullHeaders = $this->authHeaders($fullToken);
        $idA = $this->createDraftWorkOrder($workshopA, $vehicleA, $fullHeaders);
        $this->markExternalAndAddFinding($idA, $fullHeaders);
        $this->postJson("/api/v1/app/work-orders/{$idA}/external", [], $fullHeaders)->assertOk();

        [, $scopedToken] = $this->makeTenantUser($tenant, $this->externalPermissions(), ['BRANCH' => $branchB->id]);
        $scopedHeaders = $this->authHeaders($scopedToken);

        $visible = $this->getJson('/api/v1/app/external-work-order-references', $scopedHeaders)->assertOk()->json('data');
        $this->assertEmpty(array_filter($visible, fn ($row) => $row['work_order_id'] === $idA));
    }
}
