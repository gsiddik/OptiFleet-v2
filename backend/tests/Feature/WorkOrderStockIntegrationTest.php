<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use App\Domain\WorkOrder\Services\WorkOrderService;
use App\Support\TenantContext;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkOrderStockIntegrationTest extends TestCase
{
    private function setUpWorkOrder(): array
    {
        $tenant = $this->makeTenant(['code' => 'WOS-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'MAINTENANCE');
        $this->grantModule($tenant, 'WORKSHOP');
        $this->grantModule($tenant, 'WORK_ORDER');
        $this->grantModule($tenant, 'INVENTORY');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $warehouse = $this->makeWarehouse($tenant, $branch, $workshop);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id]);
        $product = $this->makeProduct($tenant);

        app(InventoryService::class)->receive($warehouse, $product, 20, 15, 'OPENING', null, null, null);

        [$user] = $this->makeTenantUser($tenant, []);
        $wo = app(WorkOrderService::class)->create($vehicle, [
            'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ], $user->id);
        app(WorkOrderService::class)->submit($wo);
        $wo = app(WorkOrderService::class)->approve($wo);
        $wo = app(WorkOrderService::class)->assign($wo);
        $wo = app(WorkOrderService::class)->schedule($wo);
        $wo = app(WorkOrderService::class)->start($wo);

        return [$tenant, $warehouse, $product, $wo];
    }

    public function test_part_request_issue_then_consume_flow(): void
    {
        [$tenant, $warehouse, $product, $wo] = $this->setUpWorkOrder();
        [, $token] = $this->makeTenantUser($tenant, ['part_request.create', 'part_request.approve', 'part_request.issue', 'inventory.issue']);
        $headers = $this->authHeaders($token);

        $requestId = $this->postJson("/api/v1/app/work-orders/{$wo->id}/part-requests", ['items' => [['product_id' => $product->id, 'quantity_requested' => 4]]], $headers)
            ->assertStatus(201)->assertJsonPath('data.status', 'REQUESTED')->json('data.id');
        $this->postJson("/api/v1/app/part-requests/{$requestId}/approve", [], $headers)->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $issued = $this->postJson("/api/v1/app/part-requests/{$requestId}/issue", ['warehouse_id' => $warehouse->id], $headers)
            ->assertOk()->assertJsonPath('data.status', 'ISSUED');

        $part = WorkOrderPlannedPart::query()->findOrFail($issued->json('data.items.0.planned_part_id'));
        $this->assertSame('ISSUED', $part->status);
        $this->assertSame($warehouse->id, $part->warehouse_id);
        $this->assertSame(15.0, (float) $part->unit_cost_at_issue);
        $this->assertSame(60.0, (float) $part->total_cost);
        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(16.0, (float) $stock->quantity_on_hand);

        $consumeResponse = $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/consume", [], $headers)->assertOk();
        $this->assertSame('CONSUMED', $consumeResponse->json('data.status'));
        $this->assertSame(4.0, (float) $consumeResponse->json('data.consumed_quantity'));
    }

    public function test_returning_more_than_issued_is_rejected(): void
    {
        [$tenant, $warehouse, $product, $wo] = $this->setUpWorkOrder();
        [, $token] = $this->makeTenantUser($tenant, ['inventory.return']);
        $headers = $this->authHeaders($token);
        $part = $this->issueThroughPartRequest($wo, $product, 2, $warehouse);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", [
            'quantity' => 5, 'condition' => 'UNUSED_NEW', 'reason' => 'Too many',
        ], $headers)->assertStatus(422);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", [
            'quantity' => 1, 'condition' => 'UNUSED_NEW', 'reason' => 'Wrong part ordered',
        ], $headers)->assertOk()->assertJsonPath('data.returned_quantity', '1.0000');
    }

    public function test_part_cost_snapshot_is_immune_to_later_average_cost_drift(): void
    {
        [, $warehouse, $product, $wo] = $this->setUpWorkOrder();
        $part = $this->issueThroughPartRequest($wo, $product, 2, $warehouse);

        // Drive the average cost up with a new, more expensive receipt after the issue.
        app(InventoryService::class)->receive($warehouse, $product, 10, 100, 'RECEIPT', null, null, null);

        $part->refresh();
        $this->assertSame(15.0, (float) $part->unit_cost_at_issue);
        $this->assertSame(30.0, (float) $part->total_cost);
    }

    public function test_direct_planned_part_add_reserve_and_issue_endpoints_are_gone(): void
    {
        [$tenant, $warehouse, $product, $wo] = $this->setUpWorkOrder();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_job.manage', 'inventory.reserve', 'inventory.issue']);
        $headers = $this->authHeaders($token);
        $part = $this->issueThroughPartRequest($wo, $product, 1, $warehouse);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts", ['product_id' => $product->id, 'description' => 'x', 'quantity' => 1], $headers)->assertNotFound();
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/reserve", [], $headers)->assertNotFound();
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/issue", [], $headers)->assertNotFound();
    }

    /**
     * G-19: work_order_planned_parts previously had no tenant_id column at all —
     * isolation was indirect (controller-enforced via the parent work_order only).
     * This proves the column is populated and the model's own global tenant
     * scope now rejects a cross-tenant lookup structurally, not just at the
     * controller layer.
     */
    public function test_planned_part_is_tenant_scoped_and_rejects_cross_tenant_access(): void
    {
        [$tenant, , $product, $wo] = $this->setUpWorkOrder();
        $partId = $this->issueThroughPartRequest($wo, $product, 1, WarehouseStock::query()->where('product_id', $product->id)->firstOrFail()->warehouse)->id;

        $part = WorkOrderPlannedPart::query()->findOrFail($partId);
        $this->assertSame($tenant->id, $part->tenant_id);
        $this->assertNotNull($part->tenant_id);

        $otherTenant = $this->makeTenant(['code' => 'WOSB-'.Str::random(4)]);
        $context = app(TenantContext::class);
        $context->setTenantId($otherTenant->id);
        try {
            $this->assertNull(WorkOrderPlannedPart::query()->find($partId));
        } finally {
            $context->setTenantId($tenant->id);
        }
    }
}
