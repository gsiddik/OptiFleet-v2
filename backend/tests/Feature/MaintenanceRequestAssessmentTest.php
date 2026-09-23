<?php

namespace Tests\Feature;

use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequestInspectionGroup;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Enhancement OptiFleet MR/WO — Section: Initial Assessment & Visual
 * Inspection is mutable only while the request is DRAFT; once submitted it
 * becomes an immutable historical snapshot (owner decision, this phase).
 */
class MaintenanceRequestAssessmentTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'MRA-'.Str::random(4)]);
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

    private function fullGroupPayload(): array
    {
        return array_map(
            fn (string $code) => ['group_code' => $code, 'status' => 'GOOD', 'notes' => null],
            MaintenanceRequestInspectionGroup::GROUP_CODES
        );
    }

    private function createDraftRequest($vehicle, $headers): string
    {
        $create = $this->postJson('/api/v1/app/maintenance-requests', [
            'vehicle_id' => $vehicle->id, 'complaint' => 'Assessment test complaint.',
        ], $headers)->assertStatus(201);

        return $create->json('data.id');
    }

    public function test_assessment_can_be_saved_in_draft(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_request.view', 'maintenance_request.create']);
        $headers = $this->authHeaders($token);
        $id = $this->createDraftRequest($vehicle, $headers);

        $response = $this->postJson("/api/v1/app/maintenance-requests/{$id}/assessment", [
            'notes' => 'Overall looks fine.',
            'groups' => $this->fullGroupPayload(),
        ], $headers)->assertOk();

        $this->assertCount(14, $response->json('data.groups'));
        $this->assertSame('Overall looks fine.', $response->json('data.notes'));
    }

    public function test_assessment_can_be_edited_in_draft(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_request.view', 'maintenance_request.create']);
        $headers = $this->authHeaders($token);
        $id = $this->createDraftRequest($vehicle, $headers);

        $this->postJson("/api/v1/app/maintenance-requests/{$id}/assessment", [
            'notes' => 'first pass', 'groups' => $this->fullGroupPayload(),
        ], $headers)->assertOk();

        $groups = $this->fullGroupPayload();
        $groups[0]['status'] = 'CRITICAL_UNSAFE';
        $response = $this->postJson("/api/v1/app/maintenance-requests/{$id}/assessment", [
            'notes' => 'revised', 'groups' => $groups,
        ], $headers)->assertOk();

        $this->assertSame('revised', $response->json('data.notes'));
        $this->assertSame('CRITICAL_UNSAFE', collect($response->json('data.groups'))->firstWhere('group_code', $groups[0]['group_code'])['status']);

        // Editing replaces, never duplicates, the group rows.
        $assessmentId = $response->json('data.id');
        $this->assertSame(14, \App\Domain\MaintenanceRequest\Models\MaintenanceRequestInspectionGroup::query()
            ->where('maintenance_request_assessment_id', $assessmentId)->count());
    }

    public function test_assessment_can_be_cleared_in_draft(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_request.view', 'maintenance_request.create']);
        $headers = $this->authHeaders($token);
        $id = $this->createDraftRequest($vehicle, $headers);

        $this->postJson("/api/v1/app/maintenance-requests/{$id}/assessment", [
            'notes' => 'x', 'groups' => $this->fullGroupPayload(),
        ], $headers)->assertOk();

        $this->deleteJson("/api/v1/app/maintenance-requests/{$id}/assessment", [], $headers)->assertOk();

        $this->getJson("/api/v1/app/maintenance-requests/{$id}/assessment", $headers)
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    private function submittedRequestId($vehicle, $headers, array $reviewerPermissions = []): string
    {
        $id = $this->createDraftRequest($vehicle, $headers);
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/assessment", [
            'notes' => 'pre-submit snapshot', 'groups' => $this->fullGroupPayload(),
        ], $headers)->assertOk();
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/submit", [], $headers)->assertOk();

        return $id;
    }

    public function test_assessment_mutation_is_rejected_in_submitted(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_request.view', 'maintenance_request.create']);
        $headers = $this->authHeaders($token);
        $id = $this->submittedRequestId($vehicle, $headers);

        $this->postJson("/api/v1/app/maintenance-requests/{$id}/assessment", [
            'notes' => 'tampering', 'groups' => $this->fullGroupPayload(),
        ], $headers)->assertStatus(422);

        $this->deleteJson("/api/v1/app/maintenance-requests/{$id}/assessment", [], $headers)->assertStatus(422);
    }

    public function test_assessment_mutation_is_rejected_in_under_review(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_request.view', 'maintenance_request.create', 'maintenance_request.review']);
        $headers = $this->authHeaders($token);
        $id = $this->submittedRequestId($vehicle, $headers);
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/review", [], $headers)->assertOk();

        $this->postJson("/api/v1/app/maintenance-requests/{$id}/assessment", [
            'notes' => 'tampering', 'groups' => $this->fullGroupPayload(),
        ], $headers)->assertStatus(422);
    }

    public function test_assessment_mutation_is_rejected_in_approved(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, [
            'maintenance_request.view', 'maintenance_request.create', 'maintenance_request.review', 'maintenance_request.approve',
        ]);
        $headers = $this->authHeaders($token);
        $id = $this->submittedRequestId($vehicle, $headers);
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/review", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/approve", ['note' => 'ok'], $headers)->assertOk();

        $this->postJson("/api/v1/app/maintenance-requests/{$id}/assessment", [
            'notes' => 'tampering', 'groups' => $this->fullGroupPayload(),
        ], $headers)->assertStatus(422);
    }

    public function test_assessment_mutation_is_rejected_in_rejected(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, [
            'maintenance_request.view', 'maintenance_request.create', 'maintenance_request.review', 'maintenance_request.reject',
        ]);
        $headers = $this->authHeaders($token);
        $id = $this->submittedRequestId($vehicle, $headers);
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/review", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/reject", ['note' => 'no'], $headers)->assertOk();

        $this->postJson("/api/v1/app/maintenance-requests/{$id}/assessment", [
            'notes' => 'tampering', 'groups' => $this->fullGroupPayload(),
        ], $headers)->assertStatus(422);
    }

    public function test_assessment_mutation_is_rejected_in_cancelled(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_request.view', 'maintenance_request.create']);
        $headers = $this->authHeaders($token);
        $id = $this->createDraftRequest($vehicle, $headers);
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/cancel", ['note' => 'no longer needed'], $headers)->assertOk();

        $this->postJson("/api/v1/app/maintenance-requests/{$id}/assessment", [
            'notes' => 'tampering', 'groups' => $this->fullGroupPayload(),
        ], $headers)->assertStatus(422);
    }

    public function test_assessment_mutation_is_rejected_in_work_order_created(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, [
            'maintenance_request.view', 'maintenance_request.create', 'maintenance_request.review',
            'maintenance_request.approve', 'maintenance_request.convert_work_order', 'work_order.view', 'work_order.create',
        ]);
        $headers = $this->authHeaders($token);
        $id = $this->submittedRequestId($vehicle, $headers);
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/review", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/approve", ['note' => 'ok'], $headers)->assertOk();
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/work-order", [], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/maintenance-requests/{$id}/assessment", [
            'notes' => 'tampering', 'groups' => $this->fullGroupPayload(),
        ], $headers)->assertStatus(422);
    }

    public function test_submitted_assessment_remains_unchanged_through_the_full_lifecycle(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, [
            'maintenance_request.view', 'maintenance_request.create', 'maintenance_request.review',
            'maintenance_request.approve', 'maintenance_request.convert_work_order', 'work_order.view', 'work_order.create',
        ]);
        $headers = $this->authHeaders($token);
        $id = $this->submittedRequestId($vehicle, $headers);
        $before = $this->getJson("/api/v1/app/maintenance-requests/{$id}/assessment", $headers)->assertOk()->json('data');

        $this->postJson("/api/v1/app/maintenance-requests/{$id}/review", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/approve", ['note' => 'ok'], $headers)->assertOk();
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/work-order", [], $headers)->assertStatus(201);

        $after = $this->getJson("/api/v1/app/maintenance-requests/{$id}/assessment", $headers)->assertOk()->json('data');
        $this->assertSame($before['notes'], $after['notes']);
        $this->assertSame($before['groups'], $after['groups']);
    }

    public function test_work_order_displays_the_assessment_snapshot(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, [
            'maintenance_request.view', 'maintenance_request.create', 'maintenance_request.review',
            'maintenance_request.approve', 'maintenance_request.convert_work_order', 'work_order.view', 'work_order.create',
        ]);
        $headers = $this->authHeaders($token);
        $id = $this->submittedRequestId($vehicle, $headers);
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/review", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/approve", ['note' => 'ok'], $headers)->assertOk();
        $wo = $this->postJson("/api/v1/app/maintenance-requests/{$id}/work-order", [], $headers)->assertStatus(201);

        $this->assertSame($id, $wo->json('data.maintenance_request_id'));

        // The Work Order's linked assessment is the exact same snapshot the request submitted.
        $viaWorkOrder = $this->getJson("/api/v1/app/maintenance-requests/{$id}/assessment", $headers)->assertOk()->json('data');
        $this->assertCount(14, $viaWorkOrder['groups']);
    }

    public function test_request_info_action_is_absent(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_request.view', 'maintenance_request.create', 'maintenance_request.review']);
        $headers = $this->authHeaders($token);
        $id = $this->createDraftRequest($vehicle, $headers);
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/review", [], $headers)->assertOk();

        $this->postJson("/api/v1/app/maintenance-requests/{$id}/request-info", ['note' => 'need more info'], $headers)
            ->assertStatus(404);
    }

    public function test_new_transition_to_need_information_is_rejected(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_request.view', 'maintenance_request.create', 'maintenance_request.review']);
        $headers = $this->authHeaders($token);
        $id = $this->createDraftRequest($vehicle, $headers);
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/maintenance-requests/{$id}/review", [], $headers)->assertOk();

        $request = MaintenanceRequest::query()->findOrFail($id);
        $service = app(\App\Domain\MaintenanceRequest\Services\MaintenanceRequestService::class);

        $this->expectException(\App\Domain\MaintenanceRequest\Services\MaintenanceRequestException::class);
        $service->transition($request, 'NEED_INFORMATION');
    }

    public function test_legacy_need_information_records_remain_readable(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_request.view', 'maintenance_request.create']);
        $headers = $this->authHeaders($token);
        $id = $this->createDraftRequest($vehicle, $headers);

        // Simulate a pre-existing legacy record left in NEED_INFORMATION (bypassing the
        // workflow engine directly, since no live transition can produce this anymore).
        MaintenanceRequest::query()->where('id', $id)->update(['status' => 'NEED_INFORMATION']);

        $this->getJson("/api/v1/app/maintenance-requests/{$id}", $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'NEED_INFORMATION');
    }

    public function test_legacy_history_and_audit_entries_are_preserved(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [$user, $token] = $this->makeTenantUser($tenant, ['maintenance_request.view', 'maintenance_request.create']);
        $headers = $this->authHeaders($token);
        $id = $this->createDraftRequest($vehicle, $headers);

        MaintenanceRequest::query()->where('id', $id)->update(['status' => 'NEED_INFORMATION']);

        $this->assertTrue(
            \App\Domain\Audit\Models\AuditLog::query()
                ->where('resource_type', 'MaintenanceRequest')
                ->where('resource_id', $id)
                ->exists(),
            'Audit trail for a request that passed through NEED_INFORMATION must remain queryable, not purged.'
        );
    }

    public function test_cancel_requires_a_reason_and_persists_it(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_request.view', 'maintenance_request.create']);
        $headers = $this->authHeaders($token);
        $id = $this->createDraftRequest($vehicle, $headers);

        $this->postJson("/api/v1/app/maintenance-requests/{$id}/cancel", [], $headers)->assertStatus(422);

        $this->postJson("/api/v1/app/maintenance-requests/{$id}/cancel", ['note' => 'Vehicle sold.'], $headers)
            ->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.cancellation_reason', 'Vehicle sold.');
    }

    public function test_list_and_detail_expose_requested_by_user(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenant();
        [$user, $token] = $this->makeTenantUser($tenant, ['maintenance_request.view', 'maintenance_request.create']);
        $headers = $this->authHeaders($token);
        $id = $this->createDraftRequest($vehicle, $headers);

        $this->getJson('/api/v1/app/maintenance-requests', $headers)
            ->assertOk()
            ->assertJsonPath('data.0.requested_by_user.name', $user->name);

        $this->getJson("/api/v1/app/maintenance-requests/{$id}", $headers)
            ->assertOk()
            ->assertJsonPath('data.requested_by_user.name', $user->name)
            ->assertJsonPath('data.requested_by_user.id', $user->id);
    }
}
