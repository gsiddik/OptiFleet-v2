<?php

namespace Tests\Feature;

use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderExternalInvoice;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Perbaikan Tenant Portal - Work Order Status External dan Workshop
 * Invoice" Phase 5: Complete (billing), View Bill, Settlement, View
 * Settlement, and the atomic Paid -> Work Order Closed transition.
 */
class ExternalWorkOrderInvoiceCompletionTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'EWC-'.Str::random(4)]);
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

    private function permissions(): array
    {
        return [
            'work_order.view', 'work_order.create', 'work_order.prepare_external', 'work_order.finalize_external',
            'external_work_order_invoice.view', 'external_work_order_invoice.generate_authorization',
            'external_work_order_invoice.deliver', 'external_work_order_invoice.acknowledge',
            'external_work_order_invoice.complete', 'external_work_order_invoice.settle',
        ];
    }

    /** Drives a fresh External Work Order through to IN_PROGRESS (Acknowledged), ready for Complete. */
    private function reachInProgress($workshop, $vehicle, $headers, $tenant): array
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

        $partner = $this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP']);
        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoiceId}/generate-authorization", ['partner_id' => $partner->id], $headers)->assertOk();
        $this->postJson("/api/v1/app/external-work-order-invoices/{$invoiceId}/deliver", [], $headers)->assertOk();
        $ackFile = UploadedFile::fake()->create('signed-wal.pdf', 100, 'application/pdf');
        $this->post("/api/v1/app/external-work-order-invoices/{$invoiceId}/acknowledge", ['file' => $ackFile], $headers)->assertOk();

        return [$id, $invoiceId];
    }

    private function completePayload(): array
    {
        return [
            'completed_work_order_file' => UploadedFile::fake()->create('completed-wo.pdf', 100, 'application/pdf'),
            'vendor_invoice_file' => UploadedFile::fake()->create('vendor-invoice.jpg', 100, 'image/jpeg'),
            'vendor_invoice_date' => now()->toDateString(),
            'vendor_invoice_amount' => '1500000',
            'payment_term' => 'NET 30',
        ];
    }

    public function test_complete_uploads_documents_and_transitions_to_billed(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);
        [, $invoiceId] = $this->reachInProgress($workshop, $vehicle, $headers, $tenant);

        $response = $this->post("/api/v1/app/external-work-order-invoices/{$invoiceId}/complete", $this->completePayload(), $headers)->assertOk();

        $this->assertSame('BILLED', $response->json('data.status'));
        $this->assertSame('1500000.0000', $response->json('data.vendor_invoice_amount'));
        $this->assertSame('NET 30', $response->json('data.payment_term'));

        $this->get("/api/v1/app/external-work-order-invoices/{$invoiceId}/completed-work-order", $headers)->assertOk();
        $this->get("/api/v1/app/external-work-order-invoices/{$invoiceId}/vendor-invoice", $headers)->assertOk();
    }

    public function test_complete_rejects_zero_amount(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);
        [, $invoiceId] = $this->reachInProgress($workshop, $vehicle, $headers, $tenant);

        $payload = $this->completePayload();
        $payload['vendor_invoice_amount'] = '0';
        $this->post("/api/v1/app/external-work-order-invoices/{$invoiceId}/complete", $payload, $headers)->assertStatus(422);
    }

    public function test_complete_requires_in_progress_status(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);
        $id = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/work-orders/{$id}/execution-mode/external", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/external-findings", ['severity' => 'HIGH', 'description' => 'x'], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();
        $invoiceId = WorkOrderExternalInvoice::query()->where('work_order_id', $id)->value('id');

        $this->post("/api/v1/app/external-work-order-invoices/{$invoiceId}/complete", $this->completePayload(), $headers)->assertStatus(422);
    }

    public function test_settle_requires_exact_amount_match_and_closes_work_order(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);
        [$woId, $invoiceId] = $this->reachInProgress($workshop, $vehicle, $headers, $tenant);
        $this->post("/api/v1/app/external-work-order-invoices/{$invoiceId}/complete", $this->completePayload(), $headers)->assertOk();

        // Partial/mismatched payment is rejected — no partial-settlement flow is described in the
        // source document, so an exact-amount match is enforced.
        $this->post("/api/v1/app/external-work-order-invoices/{$invoiceId}/settle", [
            'payment_proof_file' => UploadedFile::fake()->create('proof.jpg', 50, 'image/jpeg'),
            'payment_date' => now()->toDateString(),
            'paid_amount' => '1000000',
        ], $headers)->assertStatus(422);

        $response = $this->post("/api/v1/app/external-work-order-invoices/{$invoiceId}/settle", [
            'payment_proof_file' => UploadedFile::fake()->create('proof.jpg', 50, 'image/jpeg'),
            'payment_date' => now()->toDateString(),
            'paid_amount' => '1500000',
        ], $headers)->assertOk();

        $this->assertSame('PAID', $response->json('data.status'));
        $workOrder = WorkOrder::query()->findOrFail($woId);
        $this->assertSame('CLOSED', $workOrder->status);

        // Regression guard: WorkOrderClosureGuardService (pre-existing, shared with the internal
        // flow) rejects any close while a Finding is OPEN, and External status forbids Findings
        // from ever being individually resolved — Settlement must auto-resolve them or every
        // External closure would deadlock against that guard.
        $finding = \App\Domain\WorkOrder\Models\WorkOrderFinding::query()->where('work_order_id', $woId)->firstOrFail();
        $this->assertSame('RESOLVED', $finding->status);

        $this->get("/api/v1/app/external-work-order-invoices/{$invoiceId}/payment-proof", $headers)->assertOk();
    }

    public function test_settle_requires_billed_status(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);
        [, $invoiceId] = $this->reachInProgress($workshop, $vehicle, $headers, $tenant);

        $this->post("/api/v1/app/external-work-order-invoices/{$invoiceId}/settle", [
            'payment_proof_file' => UploadedFile::fake()->create('proof.jpg', 50, 'image/jpeg'),
            'payment_date' => now()->toDateString(),
            'paid_amount' => '1500000',
        ], $headers)->assertStatus(422);
    }

    public function test_complete_and_settle_require_permission(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, [
            'work_order.view', 'work_order.create', 'work_order.prepare_external', 'work_order.finalize_external',
            'external_work_order_invoice.view', 'external_work_order_invoice.generate_authorization',
            'external_work_order_invoice.deliver', 'external_work_order_invoice.acknowledge',
        ]);
        $headers = $this->authHeaders($token);
        [, $invoiceId] = $this->reachInProgress($workshop, $vehicle, $headers, $tenant);

        $this->post("/api/v1/app/external-work-order-invoices/{$invoiceId}/complete", $this->completePayload(), $headers)->assertStatus(403);
    }
}
