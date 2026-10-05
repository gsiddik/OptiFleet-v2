<?php

namespace Tests\Feature;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Integration\Models\IntegrationOutboxEvent;
use App\Domain\Partner\Models\Partner;
use App\Domain\WorkOrder\Models\WorkOrderExternalService;
use App\Domain\WorkOrder\Models\WorkshopInvoice;
use App\Domain\WorkOrder\Models\WorkshopInvoicePayment;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * R1 (Workshop Invoice and Settlement): the authoritative business decision
 * is that a Workshop Invoice is issued EXTERNALLY by the Workshop Partner
 * and RECORDED by an OptiFleet user — never issued by OptiFleet. This
 * supersedes the prior BLOCKED_TECHNICAL status recorded for this feature
 * in VMS_RECONCILIATION_TRACEABILITY.md and IMPROVEMENT_CONTEXT.md.
 */
class WorkshopInvoiceTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'WSI-'.Str::random(4)]);
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
            'workshop_invoice.record', 'workshop_invoice.view', 'workshop_invoice.upload_payment',
            'workshop_invoice.request_correction', 'workshop_invoice.request_cancellation',
            'workshop_invoice.verify_correction', 'workshop_invoice.verify_cancellation',
            'workshop_invoice.view_settlement_history',
        ];
    }

    /** @return array{0:string, 1:string, 2:string} [workOrderId, externalServiceId, partnerId] with the memo already COMPLETED */
    private function createCompletedMemo(array $scenario, string $token, float $cost = 1000000, ?string $partnerId = null): array
    {
        [$tenant, , $workshop, $vehicle] = $scenario;
        $headers = $this->authHeaders($token);
        $partner = $partnerId ? Partner::query()->findOrFail($partnerId) : $this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP']);

        $woId = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ], $headers)->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/work-orders/{$woId}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$woId}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$woId}/assign", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$woId}/schedule", [], $headers)->assertOk();
        $this->withApprovedWorkspace($woId);
        $this->postJson("/api/v1/app/work-orders/{$woId}/start", [], $headers)->assertOk();

        $serviceId = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services", [
            'partner_id' => $partner->id, 'description' => 'Engine overhaul', 'cost' => $cost,
        ], $headers)->assertStatus(201)->json('data.id');

        $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/complete", [], $headers)->assertOk();

        return [$woId, $serviceId, $partner->id];
    }

    private function recordInvoicePayload(array $overrides = []): array
    {
        return array_merge([
            'external_invoice_number' => 'INV-'.Str::random(6),
            'invoice_date' => '2026-09-01',
            'due_date' => '2026-09-15',
            'total_amount' => 1000000,
        ], $overrides);
    }

    public function test_recording_a_valid_external_invoice_moves_memo_to_billed(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [$woId, $serviceId] = $this->createCompletedMemo($scenario, $token);
        $headers = $this->authHeaders($token);

        $response = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload([
            'total_amount' => 1000000, 'partner_reference' => 'PARTNER-REF-1', 'notes' => 'Paid in full later',
        ]), $headers)->assertStatus(201);

        $this->assertSame('RECORDED', $response->json('data.status'));
        $this->assertSame('1000000.0000', $response->json('data.total_amount'));

        $memo = WorkOrderExternalService::query()->findOrFail($serviceId);
        $this->assertSame('BILLED', $memo->status);
        $this->assertSame($response->json('data.id'), $memo->workshop_invoice_id);

        // audit + outbox
        $this->assertTrue(AuditLog::query()->where('resource_type', 'WorkshopInvoice')->where('resource_id', $memo->workshop_invoice_id)->exists());
        $this->assertTrue(IntegrationOutboxEvent::query()->where('event_type', 'workshop_invoice.recorded')->where('aggregate_id', $memo->workshop_invoice_id)->exists());
        $this->assertTrue(IntegrationOutboxEvent::query()->where('event_type', 'maintenance_memo.billed')->where('aggregate_id', $serviceId)->exists());
    }

    public function test_cannot_record_invoice_against_a_memo_that_is_not_completed(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant, , $workshop, $vehicle] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);
        $partner = $this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP']);

        $woId = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ], $headers)->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/work-orders/{$woId}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$woId}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$woId}/assign", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$woId}/schedule", [], $headers)->assertOk();
        $this->withApprovedWorkspace($woId);
        $this->postJson("/api/v1/app/work-orders/{$woId}/start", [], $headers)->assertOk();
        $serviceId = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services", [
            'partner_id' => $partner->id, 'description' => 'Still in progress',
        ], $headers)->assertStatus(201)->json('data.id');

        $response = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload(), $headers)
            ->assertStatus(422);
        // User-facing term is "Service Invoice" (distinct from the External Workshop Invoice).
        $this->assertStringContainsString('Service Invoice', (string) $response->json('message'));
    }

    public function test_duplicate_external_invoice_number_is_rejected_after_normalization(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [$woId1, $serviceId1, $partnerId] = $this->createCompletedMemo($scenario, $token);
        [$woId2, $serviceId2] = $this->createCompletedMemo($scenario, $token, partnerId: $partnerId);
        $headers = $this->authHeaders($token);

        $this->postJson("/api/v1/app/work-orders/{$woId1}/external-services/{$serviceId1}/workshop-invoice", $this->recordInvoicePayload([
            'external_invoice_number' => 'inv-100',
        ]), $headers)->assertStatus(201);

        // same number, different case/whitespace -> still a duplicate for the SAME partner
        $this->postJson("/api/v1/app/work-orders/{$woId2}/external-services/{$serviceId2}/workshop-invoice", $this->recordInvoicePayload([
            'external_invoice_number' => '  INV-100  ',
        ]), $headers)->assertStatus(422);
    }

    public function test_cross_tenant_workshop_invoice_access_is_denied(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [$woId, $serviceId] = $this->createCompletedMemo($scenario, $token);
        $invoiceId = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload(), $this->authHeaders($token))
            ->assertStatus(201)->json('data.id');

        $otherTenant = $this->makeTenant(['code' => 'WSIB-'.Str::random(4)]);
        [, $otherToken] = $this->makeTenantUser($otherTenant, $this->fullPermissions());

        $this->getJson("/api/v1/app/workshop-invoices/{$invoiceId}", $this->authHeaders($otherToken))->assertStatus(404);
    }

    public function test_recording_invoice_without_permission_is_denied(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, array_diff($this->fullPermissions(), ['workshop_invoice.record']));
        [$woId, $serviceId] = $this->createCompletedMemo($scenario, $token);

        $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload(), $this->authHeaders($token))
            ->assertStatus(403);
    }

    public function test_reconciliation_reports_variance_against_memo_cost(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [$woId, $serviceId] = $this->createCompletedMemo($scenario, $token, cost: 1000000);
        $headers = $this->authHeaders($token);

        $invoiceId = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload([
            'total_amount' => 1150000,
        ]), $headers)->assertStatus(201)->json('data.id');

        $reconciliation = $this->getJson("/api/v1/app/workshop-invoices/{$invoiceId}/reconciliation", $headers)->assertOk();
        $this->assertSame('1000000.0000', $reconciliation->json('data.expected_amount'));
        $this->assertSame('1150000.0000', $reconciliation->json('data.invoiced_amount'));
        $this->assertSame('150000.0000', $reconciliation->json('data.variance_amount'));
        $this->assertSame('VARIANCE', $reconciliation->json('data.reconciliation_status'));

        $this->putJson("/api/v1/app/workshop-invoices/{$invoiceId}/reconciliation-note", ['reconciliation_note' => 'Extra parts approved verbally, PO to follow'], $headers)
            ->assertOk()->assertJsonPath('data.reconciliation_note', 'Extra parts approved verbally, PO to follow');
    }

    public function test_reconciliation_matches_when_invoice_equals_expected_cost(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [$woId, $serviceId] = $this->createCompletedMemo($scenario, $token, cost: 500000);
        $headers = $this->authHeaders($token);

        $invoiceId = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload([
            'total_amount' => 500000,
        ]), $headers)->assertStatus(201)->json('data.id');

        $this->getJson("/api/v1/app/workshop-invoices/{$invoiceId}/reconciliation", $headers)
            ->assertOk()->assertJsonPath('data.reconciliation_status', 'MATCHED');
    }

    public function test_reconciliation_flags_missing_expected_amount(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);
        // cost intentionally omitted at request time (nullable field) — createCompletedMemo always
        // passes a cost, so build the memo manually here without one.
        [, , $workshop, $vehicle] = $scenario;
        $partner = $this->makePartner($tenant, ['partner_type' => 'EXTERNAL_WORKSHOP']);
        $woId = $this->postJson('/api/v1/app/work-orders', ['vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000], $headers)->json('data.id');
        foreach (['submit', 'approve', 'assign', 'schedule', 'start'] as $action) {
            if ($action === 'start') {
                $this->withApprovedWorkspace($woId);
            }
            $this->postJson("/api/v1/app/work-orders/{$woId}/{$action}", [], $headers)->assertOk();
        }
        $serviceId = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services", ['partner_id' => $partner->id, 'description' => 'No estimate given'], $headers)->json('data.id');
        $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/complete", [], $headers)->assertOk();

        $invoiceId = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload(), $headers)->json('data.id');

        $reconciliation = $this->getJson("/api/v1/app/workshop-invoices/{$invoiceId}/reconciliation", $headers)->assertOk();
        $this->assertSame('NO_EXPECTED_AMOUNT', $reconciliation->json('data.reconciliation_status'));
        $this->assertContains('maintenance_memo_cost_not_recorded', $reconciliation->json('data.missing_source_records'));
    }

    public function test_payment_evidence_is_mandatory_and_moves_memo_to_paid(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [$woId, $serviceId] = $this->createCompletedMemo($scenario, $token);
        $headers = $this->authHeaders($token);

        $invoiceId = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload([
            'total_amount' => 1000000,
        ]), $headers)->assertStatus(201)->json('data.id');

        // missing evidence_url -> 422
        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/payments", [
            'payment_date' => '2026-09-20', 'paid_amount' => 1000000,
        ], $headers)->assertStatus(422);

        $payment = $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/payments", [
            'payment_date' => '2026-09-20', 'paid_amount' => 1000000, 'evidence_url' => 'https://files.example/proof.png',
            'payment_method' => 'BANK_TRANSFER', 'reference_number' => 'TRX-001',
        ], $headers)->assertStatus(201);

        $this->assertSame('1000000.0000', $payment->json('data.paid_amount'));

        $memo = WorkOrderExternalService::query()->findOrFail($serviceId);
        $this->assertSame('PAID', $memo->status);

        $this->assertTrue(IntegrationOutboxEvent::query()->where('event_type', 'maintenance_memo.paid')->where('aggregate_id', $serviceId)->exists());
    }

    public function test_duplicate_payment_submission_is_rejected(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [$woId, $serviceId] = $this->createCompletedMemo($scenario, $token);
        $headers = $this->authHeaders($token);

        $invoiceId = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload([
            'total_amount' => 1000000,
        ]), $headers)->json('data.id');

        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/payments", [
            'payment_date' => '2026-09-20', 'paid_amount' => 1000000, 'evidence_url' => 'https://files.example/proof.png',
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/payments", [
            'payment_date' => '2026-09-21', 'paid_amount' => 1000000, 'evidence_url' => 'https://files.example/proof2.png',
        ], $headers)->assertStatus(422);

        $this->assertSame(1, WorkshopInvoicePayment::query()->where('workshop_invoice_id', $invoiceId)->count());
    }

    public function test_incorrect_payment_amount_is_rejected(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [$woId, $serviceId] = $this->createCompletedMemo($scenario, $token);
        $headers = $this->authHeaders($token);

        $invoiceId = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload([
            'total_amount' => 1000000,
        ]), $headers)->json('data.id');

        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/payments", [
            'payment_date' => '2026-09-20', 'paid_amount' => 999999.99, 'evidence_url' => 'https://files.example/proof.png',
        ], $headers)->assertStatus(422);

        $memo = WorkOrderExternalService::query()->findOrFail($serviceId);
        $this->assertSame('BILLED', $memo->status);
    }

    public function test_correction_request_and_maker_checker_approval(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [$maker, $makerToken] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [$checker, $checkerToken] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [$woId, $serviceId] = $this->createCompletedMemo($scenario, $makerToken);
        $makerHeaders = $this->authHeaders($makerToken);
        $checkerHeaders = $this->authHeaders($checkerToken);

        $invoiceId = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload([
            'total_amount' => 1000000, 'external_invoice_number' => 'INV-CORR-1',
        ]), $makerHeaders)->json('data.id');

        $correction = $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/request-correction", [
            'requested_values' => ['total_amount' => 1200000],
            'reason' => 'Partner corrected a pricing typo',
        ], $makerHeaders)->assertStatus(201);
        $correctionId = $correction->json('data.id');
        $this->assertSame('1000000.0000', $correction->json('data.previous_values.total_amount'));

        $this->getJson("/api/v1/app/workshop-invoices/{$invoiceId}", $makerHeaders)->assertOk()->assertJsonPath('data.status', 'CORRECTION_REQUESTED');

        // maker cannot verify their own correction
        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/corrections/{$correctionId}/decide", [
            'decision' => 'APPROVE',
        ], $makerHeaders)->assertStatus(422);

        // a different user (checker) can
        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/corrections/{$correctionId}/decide", [
            'decision' => 'APPROVE', 'note' => 'Confirmed with partner',
        ], $checkerHeaders)->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $this->getJson("/api/v1/app/workshop-invoices/{$invoiceId}", $makerHeaders)->assertOk()
            ->assertJsonPath('data.status', 'RECORDED')
            ->assertJsonPath('data.total_amount', '1200000.0000');
    }

    public function test_correction_rejection_leaves_original_values_untouched(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $makerToken] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [, $checkerToken] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [$woId, $serviceId] = $this->createCompletedMemo($scenario, $makerToken);
        $makerHeaders = $this->authHeaders($makerToken);
        $checkerHeaders = $this->authHeaders($checkerToken);

        $invoiceId = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload([
            'total_amount' => 1000000,
        ]), $makerHeaders)->json('data.id');

        $correctionId = $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/request-correction", [
            'requested_values' => ['total_amount' => 5000000], 'reason' => 'Typo, should be rejected',
        ], $makerHeaders)->json('data.id');

        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/corrections/{$correctionId}/decide", [
            'decision' => 'REJECT', 'note' => 'Not verified with partner',
        ], $checkerHeaders)->assertOk()->assertJsonPath('data.status', 'REJECTED');

        $this->getJson("/api/v1/app/workshop-invoices/{$invoiceId}", $makerHeaders)->assertOk()
            ->assertJsonPath('data.status', 'RECORDED')
            ->assertJsonPath('data.total_amount', '1000000.0000');
    }

    public function test_cancellation_request_and_approval_reverts_memo_and_blocks_further_payment(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $makerToken] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [, $checkerToken] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [$woId, $serviceId] = $this->createCompletedMemo($scenario, $makerToken);
        $makerHeaders = $this->authHeaders($makerToken);
        $checkerHeaders = $this->authHeaders($checkerToken);

        $invoiceId = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload([
            'total_amount' => 1000000,
        ]), $makerHeaders)->json('data.id');

        $cancellationId = $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/request-cancellation", [
            'reason' => 'Invoice was issued to the wrong Work Order',
        ], $makerHeaders)->assertStatus(201)->json('data.id');

        // maker cannot self-verify
        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/cancellations/{$cancellationId}/decide", ['decision' => 'APPROVE'], $makerHeaders)
            ->assertStatus(422);

        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/cancellations/{$cancellationId}/decide", [
            'decision' => 'APPROVE', 'note' => 'Confirmed wrong WO',
        ], $checkerHeaders)->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $invoice = WorkshopInvoice::query()->findOrFail($invoiceId);
        $this->assertSame('CANCELLED', $invoice->status);

        $memo = WorkOrderExternalService::query()->findOrFail($serviceId);
        $this->assertSame('COMPLETED', $memo->status);
        $this->assertNull($memo->workshop_invoice_id);

        // a cancelled invoice can never receive a payment
        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/payments", [
            'payment_date' => '2026-09-20', 'paid_amount' => 1000000, 'evidence_url' => 'https://files.example/proof.png',
        ], $makerHeaders)->assertStatus(422);
    }

    public function test_cancellation_after_paid_preserves_payment_history_and_does_not_delete_it(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $makerToken] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [, $checkerToken] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [$woId, $serviceId] = $this->createCompletedMemo($scenario, $makerToken);
        $makerHeaders = $this->authHeaders($makerToken);
        $checkerHeaders = $this->authHeaders($checkerToken);

        $invoiceId = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload([
            'total_amount' => 1000000,
        ]), $makerHeaders)->json('data.id');

        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/payments", [
            'payment_date' => '2026-09-20', 'paid_amount' => 1000000, 'evidence_url' => 'https://files.example/proof.png',
        ], $makerHeaders)->assertStatus(201);

        $cancellationId = $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/request-cancellation", [
            'reason' => 'Duplicate invoice recorded by mistake, already paid the correct one',
        ], $makerHeaders)->json('data.id');

        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/cancellations/{$cancellationId}/decide", [
            'decision' => 'APPROVE',
        ], $checkerHeaders)->assertOk();

        $memo = WorkOrderExternalService::query()->findOrFail($serviceId);
        $this->assertSame('COMPLETED', $memo->status);

        // the payment row itself is never deleted — payment history is preserved.
        $this->assertSame(1, WorkshopInvoicePayment::query()->where('workshop_invoice_id', $invoiceId)->count());
        $payment = WorkshopInvoicePayment::query()->where('workshop_invoice_id', $invoiceId)->first();
        $this->assertSame('1000000.0000', (string) $payment->paid_amount);
    }

    public function test_cancellation_rejection_keeps_invoice_recorded(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $makerToken] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [, $checkerToken] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [$woId, $serviceId] = $this->createCompletedMemo($scenario, $makerToken);
        $makerHeaders = $this->authHeaders($makerToken);
        $checkerHeaders = $this->authHeaders($checkerToken);

        $invoiceId = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload(), $makerHeaders)->json('data.id');

        $cancellationId = $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/request-cancellation", ['reason' => 'Second-guessing'], $makerHeaders)->json('data.id');

        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/cancellations/{$cancellationId}/decide", [
            'decision' => 'REJECT', 'note' => 'Invoice is correct, keep it',
        ], $checkerHeaders)->assertOk()->assertJsonPath('data.status', 'REJECTED');

        $this->getJson("/api/v1/app/workshop-invoices/{$invoiceId}", $makerHeaders)->assertOk()->assertJsonPath('data.status', 'RECORDED');
    }

    public function test_correction_cannot_be_requested_while_another_request_is_pending(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [$woId, $serviceId] = $this->createCompletedMemo($scenario, $token);
        $headers = $this->authHeaders($token);

        $invoiceId = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload(), $headers)->json('data.id');

        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/request-cancellation", ['reason' => 'first request'], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/workshop-invoices/{$invoiceId}/request-correction", [
            'requested_values' => ['total_amount' => 2000000], 'reason' => 'second request while first pending',
        ], $headers)->assertStatus(422);
    }

    public function test_workshop_invoice_can_be_printed_once_a_template_is_published(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [$woId, $serviceId] = $this->createCompletedMemo($scenario, $token);
        $headers = $this->authHeaders($token);

        $invoiceId = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload(), $headers)->json('data.id');

        // the platform default 'workshop_invoice' template (seeded by ConfigurationDefaultsSeeder,
        // run in TestCase::setUp) already makes this printable with no tenant action required.
        $response = $this->getJson("/api/v1/app/workshop-invoices/{$invoiceId}/print", $headers);
        $response->assertStatus(200);
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_recording_invoice_twice_against_the_same_memo_is_rejected(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [$woId, $serviceId] = $this->createCompletedMemo($scenario, $token);
        $headers = $this->authHeaders($token);

        $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload(['external_invoice_number' => 'INV-A']), $headers)
            ->assertStatus(201);
        $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload(['external_invoice_number' => 'INV-B']), $headers)
            ->assertStatus(422);
    }

    public function test_index_lists_and_filters_workshop_invoices(): void
    {
        $scenario = $this->setUpTenant();
        [$tenant] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        [$woId, $serviceId, $partnerId] = $this->createCompletedMemo($scenario, $token);
        $headers = $this->authHeaders($token);

        $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$serviceId}/workshop-invoice", $this->recordInvoicePayload(), $headers)->assertStatus(201);

        $list = $this->getJson('/api/v1/app/workshop-invoices?status=RECORDED&partner_id='.$partnerId, $headers)->assertOk();
        $this->assertCount(1, $list->json('data'));
    }
}
