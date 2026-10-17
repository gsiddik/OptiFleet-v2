<?php

namespace Tests\Concerns;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Partner\Models\Partner;
use Illuminate\Support\Str;

/**
 * Builds a tenant that can record workshop invoices, i.e. produce accounting feed events through the real endpoints.
 */
trait BuildsFeedScenarios
{
    /** @return array{0: Tenant, 1: string, 2: array<string, mixed>} tenant, user token, scenario */
    protected function setUpTenant(): array
    {
        $tenant = $this->makeTenant(['code' => 'AF-'.Str::random(4)]);
        foreach (['VEHICLE', 'WORKSHOP', 'WORK_ORDER'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory(), ['default_workshop_id' => $workshop->id]);
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());

        return [$tenant, $token, compact('tenant', 'branch', 'workshop', 'vehicle')];
    }

    protected function permissions(): array
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

    /** @return array{0: string, 1: string} [workOrderId, memoId] with the memo COMPLETED */
    protected function completedMemo(array $scenario, string $token, ?Partner $partner = null): array
    {
        $headers = $this->authHeaders($token);
        $partner ??= $this->makePartner($scenario['tenant'], ['partner_type' => 'EXTERNAL_WORKSHOP', 'tax_id' => '01.234.567.8-901.000']);

        $woId = $this->postJson('/api/v1/app/work-orders', [
            'vehicle_id' => $scenario['vehicle']->id, 'workshop_id' => $scenario['workshop']->id, 'maintenance_type' => 'CORRECTIVE', 'current_odometer' => 1000,
        ], $headers)->assertStatus(201)->json('data.id');
        foreach (['submit', 'approve', 'assign', 'schedule'] as $step) {
            $this->postJson("/api/v1/app/work-orders/{$woId}/{$step}", [], $headers)->assertOk();
        }
        $this->withApprovedWorkspace($woId);
        $this->postJson("/api/v1/app/work-orders/{$woId}/start", [], $headers)->assertOk();
        $memoId = $this->postJson("/api/v1/app/work-orders/{$woId}/external-services", [
            'partner_id' => $partner->id, 'description' => 'Engine overhaul', 'cost' => 1000000,
        ], $headers)->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$memoId}/complete", [], $headers)->assertOk();

        return [$woId, $memoId];
    }

    protected function recordInvoice(string $woId, string $memoId, string $token, array $overrides = []): string
    {
        return $this->postJson("/api/v1/app/work-orders/{$woId}/external-services/{$memoId}/workshop-invoice", array_merge([
            'external_invoice_number' => 'INV-'.Str::upper(Str::random(6)),
            'invoice_date' => '2026-09-01', 'due_date' => '2026-09-15', 'currency' => 'IDR',
            'subtotal' => 900000, 'tax_total' => 99000, 'discount_total' => 0, 'total_amount' => 999000,
            'partner_reference' => 'PR-1',
            'line_items' => [['description' => 'Overhaul labour', 'quantity' => 1, 'unit_price' => 900000, 'line_total' => 900000]],
        ], $overrides), $this->authHeaders($token))->assertStatus(201)->json('data.id');
    }
}
