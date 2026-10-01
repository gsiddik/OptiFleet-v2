<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\WorkOrder\Models\SparePartSale;
use App\Domain\WorkOrder\Models\WorkOrderPartReturn;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use App\Domain\WorkOrder\Services\WorkOrderService;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G-16: Sell Sparepart. G-20: InventoryService::scrap() route/permission/UI.
 */
class SparePartSaleTest extends TestCase
{
    private function permissions(): array
    {
        return [
            'maintenance_job.manage', 'inventory.reserve', 'inventory.issue', 'inventory.return', 'inventory.scrap',
            'used_part.view', 'used_part.inspect', 'used_part.dispose', 'used_part.approve',
            'sparepart_sale.view', 'sparepart_sale.create', 'sparepart_sale.approve',
        ];
    }

    /** @return array{0: Tenant, 1: Warehouse, 2: Product, 3: WorkOrderPartReturn, 4: array} A return already FINALIZED with disposition SELL_ELIGIBLE. */
    private function setUpSellEligibleReturn(float $qty = 5): array
    {
        $tenant = $this->makeTenant(['code' => 'SALE-'.Str::random(4)]);
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
        app(InventoryService::class)->receive($warehouse, $product, 50, 15, 'OPENING', null, null, null);

        [, $token] = $this->makeTenantUser($tenant, $this->permissions());
        $headers = $this->authHeaders($token);

        $wo = app(WorkOrderService::class)->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], null);
        $wo = app(WorkOrderService::class)->submit($wo);
        $wo = app(WorkOrderService::class)->approve($wo);
        $wo = app(WorkOrderService::class)->assign($wo);
        $wo = app(WorkOrderService::class)->schedule($wo);
        $wo = app(WorkOrderService::class)->start($wo);

        // The new part installed on the vehicle (issued + consumed) — the old one it replaced is
        // then recorded as a Removed Component.
        $this->consumeOnWorkOrder($wo, $product, $qty, $warehouse);

        // A used component taken off the vehicle, received into the warehouse, then processed.
        $componentId = $this->postJson("/api/v1/app/work-orders/{$wo->id}/removed-components", [
            'product_id' => $product->id, 'quantity' => $qty, 'condition' => 'GOOD',
        ], $headers)->assertStatus(201)->json('data.id');
        $return = WorkOrderPartReturn::query()->where('work_order_removed_component_id', $componentId)->firstOrFail();
        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/receive", ['warehouse_id' => $warehouse->id], $headers)->assertOk();

        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/inspect", [
            'accepted_quantity' => $qty, 'condition' => 'USED_GOOD',
        ], $headers)->assertOk();
        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/propose-disposition", [
            'disposition' => 'SELL_ELIGIBLE',
        ], $headers)->assertOk();

        [, $inspectionApproverToken] = $this->makeTenantUser($tenant, ['used_part.approve']);
        $this->postJson("/api/v1/app/used-part-returns/{$return->id}/decide", [
            'decision' => 'APPROVE',
        ], $this->authHeaders($inspectionApproverToken))->assertOk()->assertJsonPath('data.disposition_status', 'FINALIZED');

        return [$tenant, $warehouse, $product, $return->fresh(), $headers];
    }

    /** @return array{0: Tenant, 1: Warehouse, 2: Product, 3: string} a real, persisted work_order_planned_part id. */
    private function setUpBarePlannedPart(): array
    {
        $tenant = $this->makeTenant(['code' => 'SALEX-'.Str::random(4)]);
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
        $wo = app(WorkOrderService::class)->create($vehicle, ['workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE'], null);
        $part = WorkOrderPlannedPart::query()->create(['tenant_id' => $tenant->id, 'work_order_id' => $wo->id, 'description' => 'x']);

        return [$tenant, $warehouse, $product, $part->id];
    }

    public function test_unprocessed_item_cannot_be_sold(): void
    {
        [$tenant, $warehouse, $product, $plannedPartId] = $this->setUpBarePlannedPart();
        $return = WorkOrderPartReturn::query()->create([
            'tenant_id' => $tenant->id, 'work_order_planned_part_id' => $plannedPartId,
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 5,
            'condition' => 'USED_GOOD', 'disposition_status' => 'PENDING_INSPECTION',
        ]);
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());

        $this->postJson('/api/v1/app/sparepart-sales', [
            'work_order_part_return_id' => $return->id, 'quantity' => 2, 'sale_type' => 'OPERATIONAL_REUSE',
            'buyer_type' => 'EXTERNAL', 'buyer_name' => 'Junkyard Co', 'unit_price' => 10,
        ], $this->authHeaders($token))->assertStatus(422);
    }

    public function test_faulty_sourced_item_cannot_be_sold_for_operational_reuse(): void
    {
        [$tenant, $warehouse, $product, $plannedPartId] = $this->setUpBarePlannedPart();
        // Directly craft a FINALIZED+SELL_ELIGIBLE+USED_FAULTY row to prove the
        // SparePartSaleService's own defense-in-depth check fires even if the
        // (already-blocking) Phase B gate were ever bypassed by a direct write.
        $return = WorkOrderPartReturn::query()->create([
            'tenant_id' => $tenant->id, 'work_order_planned_part_id' => $plannedPartId,
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 5, 'accepted_quantity' => 5,
            'condition' => 'USED_FAULTY', 'disposition_status' => 'FINALIZED', 'disposition' => 'SELL_ELIGIBLE',
        ]);
        [, $token] = $this->makeTenantUser($tenant, $this->permissions());

        $this->postJson('/api/v1/app/sparepart-sales', [
            'work_order_part_return_id' => $return->id, 'quantity' => 2, 'sale_type' => 'OPERATIONAL_REUSE',
            'buyer_type' => 'EXTERNAL', 'buyer_name' => 'Junkyard Co', 'unit_price' => 10,
        ], $this->authHeaders($token))->assertStatus(422);

        // SCRAP_MATERIAL sale type is still allowed for a faulty-sourced item.
        $this->postJson('/api/v1/app/sparepart-sales', [
            'work_order_part_return_id' => $return->id, 'quantity' => 2, 'sale_type' => 'SCRAP_MATERIAL',
            'buyer_type' => 'EXTERNAL', 'buyer_name' => 'Scrap Metal Co', 'unit_price' => 3,
        ], $this->authHeaders($token))->assertStatus(201);
    }

    public function test_excessive_quantity_is_rejected(): void
    {
        [, , , $return, $headers] = $this->setUpSellEligibleReturn(5);

        $this->postJson('/api/v1/app/sparepart-sales', [
            'work_order_part_return_id' => $return->id, 'quantity' => 6, 'sale_type' => 'OPERATIONAL_REUSE',
            'buyer_type' => 'EXTERNAL', 'buyer_name' => 'Junkyard Co', 'unit_price' => 10,
        ], $headers)->assertStatus(422);
    }

    public function test_eligible_quantity_can_only_be_sold_once_across_multiple_sales(): void
    {
        [, , , $return, $headers] = $this->setUpSellEligibleReturn(5);

        $this->postJson('/api/v1/app/sparepart-sales', [
            'work_order_part_return_id' => $return->id, 'quantity' => 3, 'sale_type' => 'OPERATIONAL_REUSE',
            'buyer_type' => 'EXTERNAL', 'buyer_name' => 'Buyer A', 'unit_price' => 10,
        ], $headers)->assertStatus(201);

        // 2 remaining — exactly enough for a second sale.
        $this->postJson('/api/v1/app/sparepart-sales', [
            'work_order_part_return_id' => $return->id, 'quantity' => 2, 'sale_type' => 'OPERATIONAL_REUSE',
            'buyer_type' => 'EXTERNAL', 'buyer_name' => 'Buyer B', 'unit_price' => 12,
        ], $headers)->assertStatus(201);

        // Now 0 remaining — a third sale of any size is rejected, not double-counted.
        $this->postJson('/api/v1/app/sparepart-sales', [
            'work_order_part_return_id' => $return->id, 'quantity' => 1, 'sale_type' => 'OPERATIONAL_REUSE',
            'buyer_type' => 'EXTERNAL', 'buyer_name' => 'Buyer C', 'unit_price' => 10,
        ], $headers)->assertStatus(422);
    }

    public function test_duplicate_submit_is_rejected_safely(): void
    {
        [, , , $return, $headers] = $this->setUpSellEligibleReturn(5);
        $sale = $this->createSale($return, $headers, 5);

        $this->postJson("/api/v1/app/sparepart-sales/{$sale->id}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/sparepart-sales/{$sale->id}/submit", [], $headers)->assertStatus(422);
    }

    public function test_maker_cannot_approve_own_sale(): void
    {
        [, , , $return, $headers] = $this->setUpSellEligibleReturn(5);
        $sale = $this->createSale($return, $headers, 5);
        $this->postJson("/api/v1/app/sparepart-sales/{$sale->id}/submit", [], $headers)->assertOk();

        $this->postJson("/api/v1/app/sparepart-sales/{$sale->id}/decide", ['decision' => 'APPROVE'], $headers)->assertStatus(422);
    }

    public function test_approved_sale_writes_immutable_sale_movement_without_touching_on_hand(): void
    {
        [$tenant, $warehouse, $product, $return, $headers] = $this->setUpSellEligibleReturn(5);
        $sale = $this->createSale($return, $headers, 5, 20);
        $this->postJson("/api/v1/app/sparepart-sales/{$sale->id}/submit", [], $headers)->assertOk();

        [, $approverToken] = $this->makeTenantUser($tenant, ['sparepart_sale.approve']);
        $response = $this->postJson("/api/v1/app/sparepart-sales/{$sale->id}/decide", [
            'decision' => 'APPROVE',
        ], $this->authHeaders($approverToken))->assertOk();

        $this->assertSame('APPROVED', $response->json('data.status'));
        $this->assertSame('100.0000', $response->json('data.total_amount')); // 5 * 20

        $movement = StockMovement::query()->where('reference_type', SparePartSale::class)->where('reference_id', $sale->id)->first();
        $this->assertNotNull($movement);
        $this->assertSame('SALE', $movement->movement_type);

        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(45.0, (float) $stock->quantity_on_hand); // 50 − 5 issued for the new part; the used component never entered available stock and the sale does not touch it
    }

    public function test_scrap_route_requires_permission_and_decrements_on_hand_stock(): void
    {
        $tenant = $this->makeTenant(['code' => 'SCRAP-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $warehouse = $this->makeWarehouse($tenant);
        $product = $this->makeProduct($tenant);
        app(InventoryService::class)->receive($warehouse, $product, 20, 5, 'OPENING', null, null, null);

        [, $unprivilegedToken] = $this->makeTenantUser($tenant, ['inventory.view']);
        $this->postJson('/api/v1/app/inventory/scrap', [
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 3, 'reason' => 'Damaged',
        ], $this->authHeaders($unprivilegedToken))->assertStatus(403);

        [, $token] = $this->makeTenantUser($tenant, ['inventory.view', 'inventory.scrap']);
        $this->postJson('/api/v1/app/inventory/scrap', [
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 3, 'reason' => 'Damaged',
        ], $this->authHeaders($token))->assertStatus(201);

        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(17.0, (float) $stock->quantity_on_hand);
    }

    private function createSale(WorkOrderPartReturn $return, array $headers, float $qty, float $unitPrice = 15): SparePartSale
    {
        $response = $this->postJson('/api/v1/app/sparepart-sales', [
            'work_order_part_return_id' => $return->id, 'quantity' => $qty, 'sale_type' => 'OPERATIONAL_REUSE',
            'buyer_type' => 'EXTERNAL', 'buyer_name' => 'Test Buyer', 'unit_price' => $unitPrice,
        ], $headers)->assertStatus(201);

        return SparePartSale::query()->findOrFail($response->json('data.id'));
    }
}
