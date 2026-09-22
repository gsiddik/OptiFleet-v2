<?php

namespace Tests\Feature;

use App\Domain\WorkOrder\Models\WorkOrderExternalInvoice;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * "Perbaikan Tenant Portal - Work Order Status External dan Workshop
 * Invoice" Phase 3: the External Work Order Invoice list/detail and the
 * Section 5 action-matrix enforcement. Deliver/Acknowledge/Complete/
 * Settlement themselves land in Phase 4/5 — this covers list/detail
 * access control and the action-matrix source of truth.
 */
class ExternalWorkOrderInvoiceTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'EWI-'.Str::random(4)]);
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
            'work_order.cancel_external', 'external_work_order_invoice.view',
        ];
    }

    private function finalizeAsExternal($workshop, $vehicle, $headers): string
    {
        $id = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $headers)->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/work-orders/{$id}/execution-mode/external", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/external-findings", [
            'severity' => 'HIGH', 'description' => 'Cracked cylinder head.',
        ], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();

        return $id;
    }

    public function test_index_lists_invoice_with_allowed_actions(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->finalizeAsExternal($workshop, $vehicle, $headers);

        $response = $this->getJson('/api/v1/app/external-work-order-invoices', $headers)->assertOk();
        $rows = $response->json('data');
        $row = collect($rows)->firstWhere('work_order_id', $id);

        $this->assertNotNull($row);
        $this->assertSame('NEW_EXTERNAL_WO', $row['status']);
        $this->assertSame(['generate_authorization', 'cancel', 'view_history'], $row['allowed_actions']);
    }

    public function test_history_reports_status_changes_in_order(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [$user, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->finalizeAsExternal($workshop, $vehicle, $headers);
        $invoiceId = WorkOrderExternalInvoice::query()->where('work_order_id', $id)->value('id');

        $this->postJson("/api/v1/app/work-orders/{$id}/external/cancel", ['reason' => 'no longer needed'], $headers)->assertOk();

        $response = $this->getJson("/api/v1/app/external-work-order-invoices/{$invoiceId}/history", $headers)->assertOk();
        $entries = $response->json('data');

        $this->assertGreaterThanOrEqual(2, count($entries));
        $this->assertSame('created', $entries[0]['action']);
        $this->assertSame($user->name, $entries[0]['actor_name']);
        $last = $entries[count($entries) - 1];
        $this->assertSame('CANCELLED', $last['new_values']['status'] ?? null);
    }

    public function test_show_returns_detail_with_allowed_actions(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->finalizeAsExternal($workshop, $vehicle, $headers);
        $invoiceId = WorkOrderExternalInvoice::query()->where('work_order_id', $id)->value('id');

        $response = $this->getJson("/api/v1/app/external-work-order-invoices/{$invoiceId}", $headers)->assertOk();
        $this->assertSame($id, $response->json('data.work_order_id'));
        $this->assertContains('generate_authorization', $response->json('data.allowed_actions'));
    }

    public function test_index_can_be_filtered_by_status_and_work_order_id(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, array_merge($this->externalPermissions(), ['work_order.cancel_external']));
        $headers = $this->authHeaders($token);
        $keep = $this->finalizeAsExternal($workshop, $vehicle, $headers);
        $cancelled = $this->finalizeAsExternal($workshop, $vehicle, $headers);
        $this->postJson("/api/v1/app/work-orders/{$cancelled}/external/cancel", ['reason' => 'test'], $headers)->assertOk();

        $filtered = $this->getJson('/api/v1/app/external-work-order-invoices?status=NEW_EXTERNAL_WO', $headers)->assertOk();
        $this->assertTrue(collect($filtered->json('data'))->pluck('work_order_id')->contains($keep));
        $this->assertFalse(collect($filtered->json('data'))->pluck('work_order_id')->contains($cancelled));

        $byWo = $this->getJson("/api/v1/app/external-work-order-invoices?work_order_id={$keep}", $headers)->assertOk();
        $this->assertCount(1, $byWo->json('data'));
    }

    public function test_cross_tenant_invoice_cannot_be_viewed(): void
    {
        [$tenantA, , $workshopA, $vehicleA] = $this->setUpTenant();
        [, $tokenA] = $this->makeTenantUser($tenantA, $this->externalPermissions());
        $idA = $this->finalizeAsExternal($workshopA, $vehicleA, $this->authHeaders($tokenA));
        $invoiceId = WorkOrderExternalInvoice::query()->where('work_order_id', $idA)->value('id');

        $tenantB = $this->makeTenant(['code' => 'EWI-'.Str::random(4)]);
        $this->grantModule($tenantB, 'WORK_ORDER');
        [, $tokenB] = $this->makeTenantUser($tenantB, ['external_work_order_invoice.view']);

        $this->getJson("/api/v1/app/external-work-order-invoices/{$invoiceId}", $this->authHeaders($tokenB))->assertStatus(404);
    }

    public function test_view_requires_permission(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, ['work_order.view', 'work_order.create', 'work_order.prepare_external', 'work_order.finalize_external']);
        $headers = $this->authHeaders($token);
        $this->finalizeAsExternal($workshop, $vehicle, $headers);

        $this->getJson('/api/v1/app/external-work-order-invoices', $headers)->assertStatus(403);
    }

    #[DataProvider('actionMatrixProvider')]
    public function test_allowed_actions_matches_the_action_matrix(string $status, string $walStatus, array $expected): void
    {
        $invoice = new WorkOrderExternalInvoice(['status' => $status, 'work_authorization_status' => $walStatus]);

        $this->assertSame($expected, $invoice->allowedActions());
    }

    public static function actionMatrixProvider(): array
    {
        return [
            'new external wo, wal not generated' => ['NEW_EXTERNAL_WO', 'NOT_GENERATED', ['generate_authorization', 'cancel', 'view_history']],
            'new external wo, wal generated' => ['NEW_EXTERNAL_WO', 'GENERATED', ['view_authorization', 'deliver', 'cancel', 'view_history']],
            'delivered' => ['DELIVERED', 'GENERATED', ['view_authorization', 'acknowledge', 'cancel', 'view_history']],
            'in progress' => ['IN_PROGRESS', 'ACKNOWLEDGED', ['view_acknowledgement', 'complete', 'view_history']],
            'billed' => ['BILLED', 'ACKNOWLEDGED', ['view_bill', 'settle', 'view_history']],
            'paid' => ['PAID', 'ACKNOWLEDGED', ['view_settlement', 'view_history']],
            'cancelled' => ['CANCELLED', 'NOT_GENERATED', ['view_history']],
        ];
    }
}
