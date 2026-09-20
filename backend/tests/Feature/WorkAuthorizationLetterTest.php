<?php

namespace Tests\Feature;

use App\Domain\WorkOrder\Models\WorkOrderExternalInvoice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Perbaikan Tenant Portal - Work Order Status External dan Workshop
 * Invoice" Phase 4: Generate Work Authorization, View Work Authorization,
 * Deliver, Acknowledge (secure file upload/serve).
 */
class WorkAuthorizationLetterTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'WAL-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'MAINTENANCE');
        $this->grantModule($tenant, 'WORKSHOP');
        $this->grantModule($tenant, 'WORK_ORDER');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id, 'brand' => 'Isuzu', 'model' => 'Elf']);

        return [$tenant, $branch, $workshop, $vehicle];
    }

    private function permissions(): array
    {
        return [
            'work_order.view', 'work_order.create', 'work_order.cancel',
            'work_order.prepare_external', 'work_order.finalize_external',
            'external_work_order_invoice.view', 'external_work_order_invoice.generate_authorization',
            'external_work_order_invoice.deliver', 'external_work_order_invoice.acknowledge',
        ];
    }

    private function finalizeAsExternal($workshop, $vehicle, $headers): array
    {
        $id = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/work-orders/{$id}/execution-mode/external", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/external-findings", [
            'severity' => 'HIGH', 'description' => 'Cracked cylinder head.',
        ], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();
        $invoiceId = WorkOrderExternalInvoice::query()->where('work_order_id', $id)->value('id');

        return [$id, $invoiceId];
    }

    public function test_generate_authorization_snapshots_vendor_vehicle_and_company(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);
        [, $invoiceId] = $this->finalizeAsExternal($workshop, $vehicle, $headers);
        $partner = $this->makePartner($tenant, [
            'partner_type' => 'EXTERNAL_WORKSHOP', 'name' => 'Bengkel Jaya', 'address' => 'Jl. Merdeka 1',
            'contact_name' => 'Budi', 'contact_phone' => '0812345678',
        ]);

        $response = $this->postJson("/api/v1/app/external-work-order-invoices/{$invoiceId}/generate-authorization", [
            'partner_id' => $partner->id,
        ], $headers)->assertOk();

        $this->assertSame('GENERATED', $response->json('data.work_authorization_status'));
        $this->assertStringStartsWith('WAL/', $response->json('data.wal_number'));
        $this->assertSame('Bengkel Jaya', $response->json('data.wal_workshop_name'));
        $this->assertSame('Jl. Merdeka 1', $response->json('data.wal_workshop_address'));
        $this->assertSame('Budi', $response->json('data.wal_workshop_pic'));
        $this->assertSame('Isuzu Elf', $response->json('data.wal_vehicle_make_model'));
        $this->assertSame(1, $response->json('data.wal_revision'));
    }

    public function test_generate_authorization_rejects_non_workshop_or_inactive_partner(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);
        [, $invoiceId] = $this->finalizeAsExternal($workshop, $vehicle, $headers);

        $supplier = $this->makePartner($tenant, ['partner_type' => 'SPARE_PART_SUPPLIER']);
        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoiceId}/generate-authorization", [
            'partner_id' => $supplier->id,
        ], $headers)->assertStatus(422);

        $inactiveWorkshop = $this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP', 'status' => 'INACTIVE']);
        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoiceId}/generate-authorization", [
            'partner_id' => $inactiveWorkshop->id,
        ], $headers)->assertStatus(422);
    }

    public function test_generate_authorization_is_rejected_once_already_generated(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);
        [, $invoiceId] = $this->finalizeAsExternal($workshop, $vehicle, $headers);
        $partner = $this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP']);

        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoiceId}/generate-authorization", ['partner_id' => $partner->id], $headers)->assertOk();
        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoiceId}/generate-authorization", ['partner_id' => $partner->id], $headers)->assertStatus(422);
    }

    public function test_view_authorization_returns_pdf_only_after_generation(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);
        [, $invoiceId] = $this->finalizeAsExternal($workshop, $vehicle, $headers);

        $this->getJson("/api/v1/app/external-work-order-invoices/{$invoiceId}/authorization", $headers)->assertStatus(404);

        $partner = $this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP']);
        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoiceId}/generate-authorization", ['partner_id' => $partner->id], $headers)->assertOk();

        $response = $this->get("/api/v1/app/external-work-order-invoices/{$invoiceId}/authorization", $headers);
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_deliver_requires_generated_authorization(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);
        [, $invoiceId] = $this->finalizeAsExternal($workshop, $vehicle, $headers);

        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoiceId}/deliver", [], $headers)->assertStatus(422);

        $partner = $this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP']);
        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoiceId}/generate-authorization", ['partner_id' => $partner->id], $headers)->assertOk();

        $response = $this->postJson("/api/v1/app/external-work-order-invoices/{$invoiceId}/deliver", [], $headers)->assertOk();
        $this->assertSame('DELIVERED', $response->json('data.status'));
        $this->assertNotNull($response->json('data.delivered_at'));
    }

    public function test_acknowledge_uploads_file_and_transitions_to_in_progress(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);
        [, $invoiceId] = $this->finalizeAsExternal($workshop, $vehicle, $headers);
        $partner = $this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP']);
        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoiceId}/generate-authorization", ['partner_id' => $partner->id], $headers)->assertOk();
        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoiceId}/deliver", [], $headers)->assertOk();

        $file = UploadedFile::fake()->create('signed-wal.pdf', 100, 'application/pdf');
        $response = $this->post("/api/v1/app/external-work-order-invoices/{$invoiceId}/acknowledge", ['file' => $file], $headers)->assertOk();

        $this->assertSame('IN_PROGRESS', $response->json('data.status'));
        $this->assertSame('ACKNOWLEDGED', $response->json('data.work_authorization_status'));

        $download = $this->get("/api/v1/app/external-work-order-invoices/{$invoiceId}/acknowledgement", $headers);
        $download->assertOk();
        $this->assertSame('application/pdf', $download->headers->get('Content-Type'));
    }

    public function test_acknowledge_rejects_unsupported_file_type(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);
        [, $invoiceId] = $this->finalizeAsExternal($workshop, $vehicle, $headers);
        $partner = $this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP']);
        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoiceId}/generate-authorization", ['partner_id' => $partner->id], $headers)->assertOk();
        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoiceId}/deliver", [], $headers)->assertOk();

        $file = UploadedFile::fake()->create('malware.exe', 10, 'application/x-msdownload');
        $this->post("/api/v1/app/external-work-order-invoices/{$invoiceId}/acknowledge", ['file' => $file], $headers)->assertStatus(422);
    }

    public function test_acknowledge_requires_delivered_status(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);
        [, $invoiceId] = $this->finalizeAsExternal($workshop, $vehicle, $headers);

        $file = UploadedFile::fake()->create('signed-wal.pdf', 100, 'application/pdf');
        $this->post("/api/v1/app/external-work-order-invoices/{$invoiceId}/acknowledge", ['file' => $file], $headers)->assertStatus(422);
    }

    public function test_generate_authorization_requires_permission(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, [
            'work_order.view', 'work_order.create', 'work_order.prepare_external', 'work_order.finalize_external', 'external_work_order_invoice.view',
        ]);
        $headers = $this->authHeaders($token);
        [, $invoiceId] = $this->finalizeAsExternal($workshop, $vehicle, $headers);
        $partner = $this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP']);

        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoiceId}/generate-authorization", ['partner_id' => $partner->id], $headers)->assertStatus(403);
    }

    public function test_cross_tenant_partner_cannot_be_used(): void
    {
        [$tenantA, , $workshopA, $vehicleA] = $this->setUpTenant();
        [, $tokenA] = $this->makeTenantUser($tenantA, $this->permissions());
        $headersA = $this->authHeaders($tokenA);
        [, $invoiceId] = $this->finalizeAsExternal($workshopA, $vehicleA, $headersA);

        $tenantB = $this->makeTenant(['code' => 'WAL-'.Str::random(4)]);
        $partnerB = $this->makePartner($tenantB, ['partner_type' => 'EXTERNAL_WORKSHOP']);

        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoiceId}/generate-authorization", [
            'partner_id' => $partnerB->id,
        ], $headersA)->assertStatus(404);
    }
}
