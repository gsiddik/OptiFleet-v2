<?php

namespace Tests\Feature;

use App\Domain\WorkOrder\Models\WorkOrderExternalInvoice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Perbaikan Tenant Portal - Work Order Status External dan Workshop
 * Invoice" Phase 1: domain model and database foundation for the External
 * Work Order Invoice — evolved from the old WorkOrderExternalReference
 * placeholder into the full New External WO -> ... -> Paid lifecycle
 * aggregate. Deliver/Acknowledge/Complete/Settlement actions themselves
 * are built in later phases; this file covers what Phase 1 actually adds:
 * the model/table foundation, the finalize-creates-one-row guarantee at
 * the DB level, the Cancel/Revise gating added to ExternalWorkOrderService,
 * and Auditable history.
 */
class WorkOrderExternalInvoiceTest extends TestCase
{
    private function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'WEI-'.Str::random(4)]);
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
            'work_order.cancel_external',
        ];
    }

    private function finalizeAsExternal($workshop, $vehicle, $headers): string
    {
        $id = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $vehicle->id, 'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ], $headers)->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/work-orders/{$id}/execution-mode/external", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$id}/external-findings", [
            'severity' => 'HIGH', 'description' => 'Cracked cylinder head.',
        ], $headers)->assertStatus(201);
        $this->postJson("/api/v1/app/work-orders/{$id}/external", [], $headers)->assertOk();

        return $id;
    }

    public function test_finalize_creates_invoice_with_new_external_wo_status(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $id = $this->finalizeAsExternal($workshop, $vehicle, $this->authHeaders($token));

        $invoice = WorkOrderExternalInvoice::query()->where('work_order_id', $id)->firstOrFail();
        $this->assertSame('NEW_EXTERNAL_WO', $invoice->status);
        $this->assertSame('NOT_GENERATED', $invoice->work_authorization_status);
    }

    public function test_a_work_order_cannot_have_two_external_invoices_at_the_database_level(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $id = $this->finalizeAsExternal($workshop, $vehicle, $this->authHeaders($token));
        $invoice = WorkOrderExternalInvoice::query()->where('work_order_id', $id)->firstOrFail();

        $this->expectException(QueryException::class);
        WorkOrderExternalInvoice::query()->create([
            'tenant_id' => $invoice->tenant_id, 'branch_id' => $invoice->branch_id, 'work_order_id' => $id,
        ]);
    }

    public function test_revise_is_rejected_once_invoice_has_moved_past_new_external_wo(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->finalizeAsExternal($workshop, $vehicle, $headers);

        WorkOrderExternalInvoice::query()->where('work_order_id', $id)->update(['status' => 'DELIVERED']);

        $this->postJson("/api/v1/app/work-orders/{$id}/external/revise", [], $headers)->assertStatus(422);
    }

    public function test_cancel_synchronizes_invoice_status_to_cancelled(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->finalizeAsExternal($workshop, $vehicle, $headers);

        $this->postJson("/api/v1/app/work-orders/{$id}/external/cancel", ['reason' => 'No longer needed.'], $headers)->assertOk();

        $invoice = WorkOrderExternalInvoice::query()->where('work_order_id', $id)->firstOrFail();
        $this->assertSame('CANCELLED', $invoice->status);
        $this->assertSame('No longer needed.', $invoice->cancellation_reason);
        $this->assertNotNull($invoice->cancelled_at);
    }

    public function test_cancel_is_rejected_once_invoice_is_no_longer_business_safe_to_cancel(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->finalizeAsExternal($workshop, $vehicle, $headers);

        WorkOrderExternalInvoice::query()->where('work_order_id', $id)->update(['status' => 'IN_PROGRESS']);

        $this->postJson("/api/v1/app/work-orders/{$id}/external/cancel", ['reason' => 'Too late.'], $headers)->assertStatus(422);

        $workOrder = \App\Domain\WorkOrder\Models\WorkOrder::query()->findOrFail($id);
        $this->assertSame('EXTERNAL', $workOrder->status, 'A rejected cancel must not leave the Work Order half-transitioned.');
    }

    public function test_invoice_changes_are_recorded_in_the_generic_audit_log(): void
    {
        [$tenant, , $workshop, $vehicle] = $this->setUpTenant();
        [, $token] = $this->makeTenantUser($tenant, $this->externalPermissions());
        $headers = $this->authHeaders($token);
        $id = $this->finalizeAsExternal($workshop, $vehicle, $headers);
        $invoice = WorkOrderExternalInvoice::query()->where('work_order_id', $id)->firstOrFail();

        $this->postJson("/api/v1/app/work-orders/{$id}/external/cancel", ['reason' => 'Testing audit trail.'], $headers)->assertOk();

        $log = \App\Domain\Audit\Models\AuditLog::query()
            ->where('resource_type', 'WorkOrderExternalInvoice')
            ->where('resource_id', $invoice->id)
            ->where('action', 'updated')
            ->latest('created_at')
            ->first();

        $this->assertNotNull($log, 'Cancelling the invoice must be captured in the generic audit log.');
        $this->assertSame('NEW_EXTERNAL_WO', $log->old_values['status'] ?? null);
        $this->assertSame('CANCELLED', $log->new_values['status'] ?? null);
    }
}
