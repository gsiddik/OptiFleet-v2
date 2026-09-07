<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Tests\TestCase;

class WorkOrderStockIntegrationTest extends TestCase
{
    private function setUpWorkOrder(): array
    {
        $tenant = $this->makeTenant(['code' => 'WOS-'.\Illuminate\Support\Str::random(4)]);
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

    public function test_planned_part_reserve_issue_consume_flow_uses_preferred_warehouse(): void
    {
        [$tenant, $warehouse, $product, $wo] = $this->setUpWorkOrder();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_job.manage', 'inventory.reserve', 'inventory.issue', 'inventory.return']);
        $headers = $this->authHeaders($token);

        $addResponse = $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts", [
            'product_id' => $product->id, 'description' => 'Brake pad set', 'quantity' => 4,
        ], $headers)->assertStatus(201);
        $part = WorkOrderPlannedPart::query()->findOrFail($addResponse->json('data.id'));
        $this->assertSame(4.0, (float) $part->planned_quantity);

        $reserveResponse = $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/reserve", [], $headers)->assertOk();
        $this->assertSame($warehouse->id, $reserveResponse->json('data.warehouse_id'));
        $this->assertSame('RESERVED', $reserveResponse->json('data.status'));

        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(4.0, (float) $stock->quantity_reserved);

        $issueResponse = $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/issue", [], $headers)->assertOk();
        $this->assertSame('ISSUED', $issueResponse->json('data.status'));
        $this->assertSame(15.0, (float) $issueResponse->json('data.unit_cost_at_issue'));
        $this->assertSame(60.0, (float) $issueResponse->json('data.total_cost'));

        $consumeResponse = $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/consume", [], $headers)->assertOk();
        $this->assertSame('CONSUMED', $consumeResponse->json('data.status'));
        $this->assertSame(4.0, (float) $consumeResponse->json('data.consumed_quantity'));
    }

    public function test_returning_more_than_issued_is_rejected(): void
    {
        [$tenant, $warehouse, $product, $wo] = $this->setUpWorkOrder();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_job.manage', 'inventory.reserve', 'inventory.issue', 'inventory.return']);
        $headers = $this->authHeaders($token);

        $addResponse = $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts", [
            'product_id' => $product->id, 'description' => 'Air filter', 'quantity' => 2,
        ], $headers)->assertStatus(201);
        $part = WorkOrderPlannedPart::query()->findOrFail($addResponse->json('data.id'));

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/reserve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/issue", [], $headers)->assertOk();

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", [
            'quantity' => 5, 'reason' => 'Too many',
        ], $headers)->assertStatus(422);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/return", [
            'quantity' => 1, 'reason' => 'Wrong part ordered',
        ], $headers)->assertOk()->assertJsonPath('data.returned_quantity', '1.0000');

        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(19.0, (float) $stock->quantity_on_hand);
    }

    public function test_part_cost_snapshot_is_immune_to_later_average_cost_drift(): void
    {
        [$tenant, $warehouse, $product, $wo] = $this->setUpWorkOrder();
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_job.manage', 'inventory.reserve', 'inventory.issue']);
        $headers = $this->authHeaders($token);

        $addResponse = $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts", [
            'product_id' => $product->id, 'description' => 'Oil filter', 'quantity' => 2,
        ], $headers)->assertStatus(201);
        $part = WorkOrderPlannedPart::query()->findOrFail($addResponse->json('data.id'));

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/reserve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/issue", [], $headers)->assertOk();

        // Drive the average cost up with a new, more expensive receipt after the issue.
        app(InventoryService::class)->receive($warehouse, $product, 10, 100, 'RECEIPT', null, null, null);

        $part->refresh();
        $this->assertSame(15.0, (float) $part->unit_cost_at_issue);
        $this->assertSame(30.0, (float) $part->total_cost);
    }

    public function test_stock_actions_are_only_visible_when_permitted(): void
    {
        [$tenant, , $product, $wo] = $this->setUpWorkOrder();
        [, $unprivilegedToken] = $this->makeTenantUser($tenant, ['maintenance_job.manage']); // no inventory.reserve

        $addResponse = $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts", [
            'product_id' => $product->id, 'description' => 'Spark plug', 'quantity' => 1,
        ], $this->authHeaders($unprivilegedToken))->assertStatus(201);
        $part = WorkOrderPlannedPart::query()->findOrFail($addResponse->json('data.id'));

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/planned-parts/{$part->id}/reserve", [], $this->authHeaders($unprivilegedToken))
            ->assertStatus(403);
    }
}
