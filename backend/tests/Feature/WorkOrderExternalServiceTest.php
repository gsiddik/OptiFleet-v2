<?php

namespace Tests\Feature;

use App\Domain\Configuration\Services\DocumentTemplateService;
use App\Domain\Partner\Models\PartnerPerformanceEvent;
use App\Domain\WorkOrder\Models\WorkOrderExternalService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase G — G-07: previously the TOWING_PROVIDER/OTHER_SERVICE_PROVIDER
 * Partner types existed in the enum but nothing in the application ever
 * referenced them — a Work Order had no way to record that an external
 * party performed work on its behalf.
 */
class WorkOrderExternalServiceTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'WOX-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
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
            'work_order.view', 'work_order.create', 'work_order.submit', 'work_order.approve',
            'work_order.assign', 'work_order.schedule', 'work_order.start', 'work_order.cancel',
            'work_order_external_service.create', 'work_order_external_service.complete', 'work_order_external_service.cancel',
        ];
    }

    private function createInProgressWorkOrder(array $scenario, string $token): string
    {
        [, , $workshop, $vehicle] = $scenario;
        $headers = $this->authHeaders($token);
        $create = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201);
        $id = $create->json('data.id');

        $this->postJson("/api/v1/app/work-orders/{$id}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/assign", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/schedule", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/start", [], $headers)->assertOk();

        return $id;
    }

    public function test_towing_provider_partner_can_be_requested_for_a_work_order(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $partner = $this->makePartner($tenant, ['partner_type' => 'TOWING_PROVIDER']);

        $woId = $this->createInProgressWorkOrder($scenario, $token);

        $response = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services", [
            'partner_id' => $partner->id, 'description' => 'Towed vehicle from breakdown site to workshop', 'cost' => '350000',
        ], $this->authHeaders($token))->assertStatus(201);

        $this->assertSame('REQUESTED', $response->json('data.status'));
        $this->assertSame($partner->id, $response->json('data.partner_id'));
    }

    public function test_external_service_memo_fields_are_optional_and_stored(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $partner = $this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP']);

        $woId = $this->createInProgressWorkOrder($scenario, $token);

        $response = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services", [
            'partner_id' => $partner->id, 'description' => 'Engine overhaul at partner workshop',
            'photo_evidence' => 'https://files.example/memo/before-1.jpg',
            'condition_notes' => 'Visible oil leak at cylinder head',
            'priority' => 'HIGH',
        ], $this->authHeaders($token))->assertStatus(201);

        $this->assertSame('https://files.example/memo/before-1.jpg', $response->json('data.photo_evidence'));
        $this->assertSame('Visible oil leak at cylinder head', $response->json('data.condition_notes'));
        $this->assertSame('HIGH', $response->json('data.priority'));
    }

    public function test_external_service_cannot_be_requested_before_work_order_is_executable(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant, , $workshop, $vehicle] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $partner = $this->makePartner($tenant, ['partner_type' => 'OTHER_SERVICE_PROVIDER']);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/work-orders/{$create->json('data.id')}/external-services", [
            'partner_id' => $partner->id, 'description' => 'Not yet in progress',
        ], $headers)->assertStatus(422);
    }

    public function test_completing_an_external_service_records_partner_performance_and_cannot_repeat(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [$user, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $partner = $this->makePartner($tenant, ['partner_type' => 'OTHER_SERVICE_PROVIDER']);
        $woId = $this->createInProgressWorkOrder($scenario, $token);
        $headers = $this->authHeaders($token);

        $create = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services", [
            'partner_id' => $partner->id, 'description' => 'Mobile welding repair on-site', 'cost' => '500000',
        ], $headers)->assertStatus(201);
        $serviceId = $create->json('data.id');

        $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/complete", [], $headers)->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.completed_by', $user->id);

        $this->assertTrue(
            PartnerPerformanceEvent::query()->where('partner_id', $partner->id)->where('event_type', 'EXTERNAL_SERVICE_COMPLETED')->exists()
        );

        $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/complete", [], $headers)->assertStatus(422);
    }

    public function test_cancelling_an_external_service_does_not_record_partner_performance(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $partner = $this->makePartner($tenant, ['partner_type' => 'TOWING_PROVIDER']);
        $woId = $this->createInProgressWorkOrder($scenario, $token);
        $headers = $this->authHeaders($token);

        $create = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services", [
            'partner_id' => $partner->id, 'description' => 'Requested but no longer needed',
        ], $headers)->assertStatus(201);
        $serviceId = $create->json('data.id');

        $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/cancel", [], $headers)->assertOk()
            ->assertJsonPath('data.status', 'CANCELLED');

        $this->assertFalse(
            PartnerPerformanceEvent::query()->where('partner_id', $partner->id)->where('event_type', 'EXTERNAL_SERVICE_COMPLETED')->exists()
        );

        $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/complete", [], $headers)->assertStatus(422);
    }

    public function test_partner_from_another_tenant_cannot_be_used(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $otherTenant = $this->makeTenant(['code' => 'WOXB-'.Str::random(4)]);
        $foreignPartner = $this->makePartner($otherTenant, ['partner_type' => 'TOWING_PROVIDER']);
        $woId = $this->createInProgressWorkOrder($scenario, $token);

        $this->postJson("/api/v1/app/work-orders/{$woId}/external-services", [
            'partner_id' => $foreignPartner->id, 'description' => 'Cross tenant partner',
        ], $this->authHeaders($token))->assertStatus(404);
    }

    public function test_external_service_action_requires_permission(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, array_diff($this->fullPermissions(), ['work_order_external_service.create']));
        $partner = $this->makePartner($tenant, ['partner_type' => 'TOWING_PROVIDER']);
        $woId = $this->createInProgressWorkOrder($scenario, $token);

        $this->postJson("/api/v1/app/work-orders/{$woId}/external-services", [
            'partner_id' => $partner->id, 'description' => 'No permission for this',
        ], $this->authHeaders($token))->assertStatus(403);
    }

    /** Final reconciliation (queued ADJUST): VMS's Maintenance Memo "Save and Print". */
    public function test_maintenance_memo_can_be_printed_once_a_template_is_published(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $partner = $this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP']);
        $woId = $this->createInProgressWorkOrder($scenario, $token);
        $headers = $this->authHeaders($token);

        $create = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services", [
            'partner_id' => $partner->id, 'description' => 'Engine overhaul at partner workshop',
        ], $headers)->assertStatus(201);
        $serviceId = $create->json('data.id');

        $templates = app(DocumentTemplateService::class);
        $set = $templates->findOrCreateSet($tenant->id, 'maintenance_memo', 'TENANT', null, 'Maintenance Memo');
        $templates->publish($templates->createDraft($set, [
            'html' => 'Memo for {{work_order.number}} — {{maintenance_memo.description}} at {{partner.name}}',
        ], null), 'maintenance_memo', null);

        $response = $this->getJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/print", $headers);
        $response->assertStatus(200);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_maintenance_memo_print_belongs_to_correct_work_order(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $partner = $this->makePartner($tenant, ['partner_type' => 'TOWING_PROVIDER']);
        $woAId = $this->createInProgressWorkOrder($scenario, $token);
        $woBId = $this->createInProgressWorkOrder($scenario, $token);
        $headers = $this->authHeaders($token);

        $create = $this->postJson("/api/v1/app/work-orders/{$woAId}/external-services", [
            'partner_id' => $partner->id, 'description' => 'Belongs to WO A',
        ], $headers)->assertStatus(201);
        $serviceId = $create->json('data.id');

        $this->getJson("/api/v1/app/work-orders/{$woBId}/external-services/{$serviceId}/print", $headers)->assertStatus(404);
    }

    public function test_cross_tenant_work_order_cannot_receive_external_service(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $partner = $this->makePartner($tenant, ['partner_type' => 'TOWING_PROVIDER']);
        $woId = $this->createInProgressWorkOrder($scenario, $token);

        $otherTenant = $this->makeTenant(['code' => 'WOXC-'.Str::random(4)]);
        $this->grantModule($otherTenant, 'WORK_ORDER');
        [, $otherToken] = $this->makeTenantUser($otherTenant, $this->fullPermissions());

        $this->postJson("/api/v1/app/work-orders/{$woId}/external-services", [
            'partner_id' => $partner->id, 'description' => 'Cross tenant work order',
        ], $this->authHeaders($otherToken))->assertStatus(404);
    }

    public function test_external_service_belongs_to_correct_work_order(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $partner = $this->makePartner($tenant, ['partner_type' => 'TOWING_PROVIDER']);
        $woAId = $this->createInProgressWorkOrder($scenario, $token);
        $woBId = $this->createInProgressWorkOrder($scenario, $token);
        $headers = $this->authHeaders($token);

        $create = $this->postJson("/api/v1/app/work-orders/{$woAId}/external-services", [
            'partner_id' => $partner->id, 'description' => 'Belongs to WO A',
        ], $headers)->assertStatus(201);
        $serviceId = $create->json('data.id');

        $this->postJson("/api/v1/app/work-orders/{$woBId}/external-services/{$serviceId}/complete", [], $headers)->assertStatus(404);
    }

    public function test_work_order_show_lists_external_services_with_partner(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $partner = $this->makePartner($tenant, ['partner_type' => 'TOWING_PROVIDER', 'name' => 'Acme Towing']);
        $woId = $this->createInProgressWorkOrder($scenario, $token);
        $headers = $this->authHeaders($token);

        $this->postJson("/api/v1/app/work-orders/{$woId}/external-services", [
            'partner_id' => $partner->id, 'description' => 'Towing service',
        ], $headers)->assertStatus(201);

        $response = $this->getJson("/api/v1/app/work-orders/{$woId}", $headers)->assertOk();
        $this->assertCount(1, $response->json('data.external_services'));
        $this->assertSame('Acme Towing', $response->json('data.external_services.0.partner.name'));

        $this->assertSame(1, WorkOrderExternalService::query()->where('work_order_id', $woId)->count());
    }
}
