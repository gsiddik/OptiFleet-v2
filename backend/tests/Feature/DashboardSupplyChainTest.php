<?php

namespace Tests\Feature;

use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Partner\Models\Partner;
use App\Domain\Procurement\Services\PurchaseOrderService;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Services\TireService;
use App\Domain\Warranty\Models\Warranty;
use App\Domain\Warranty\Services\WarrantyClaimService;
use Tests\TestCase;

class DashboardSupplyChainTest extends TestCase
{
    public function test_dashboard_reports_phase4_supply_chain_metrics_behind_permissions(): void
    {
        $tenant = $this->makeTenant(['code' => 'DASH-'.\Illuminate\Support\Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'PROCUREMENT');
        $this->grantModule($tenant, 'PARTNER');
        $this->grantModule($tenant, 'TIRE');
        $this->grantModule($tenant, 'WARRANTY');
        $branch = $this->makeBranch($tenant);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        $product = $this->makeProduct($tenant);
        $tireProduct = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE']);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        $vendor = $this->makePartner($tenant);

        // Low stock: available (5) is above zero but at/under reorder_point (10).
        app(InventoryService::class)->receive($warehouse, $product, 5, 10, 'OPENING', null, null, null);
        \App\Domain\Inventory\Models\WarehouseStock::query()
            ->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)
            ->update(['reorder_point' => 10]);
        app(InventoryService::class)->reserve($warehouse, $product, 2, null, null, null);

        // A PO left ISSUED (goods receipt pending).
        $poService = app(PurchaseOrderService::class);
        $po = $poService->create($vendor, $warehouse, [], [
            ['product_id' => $product->id, 'quantity_ordered' => 20, 'unit_price' => 10],
        ], null);
        $po = $poService->transition($po, 'SUBMITTED');
        $po = $poService->approve($po, null);
        $poService->transition($po, 'ISSUED');

        // A tire installed and in use.
        $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $tireProduct->id, 'serial_number' => 'DASH-TIRE-01', 'current_status' => 'IN_STOCK']);
        app(TireService::class)->install($tire, $vehicle, 'FRONT_LEFT', 1000, null, null);

        // An active warranty claim.
        $warranty = Warranty::query()->create([
            'tenant_id' => $tenant->id, 'coverage_basis' => 'DATE', 'duration_months' => 12, 'starts_at' => now(), 'status' => 'ACTIVE',
        ]);
        $claimService = app(WarrantyClaimService::class);
        $claim = $claimService->create($vehicle, [
            'warranty_id' => $warranty->id, 'failure_date' => now()->toDateString(), 'reason' => 'Test claim',
        ], null);
        $claimService->transition($claim, 'SUBMITTED');

        // Sections are gated by module AND view permission; money needs dashboard.finance.view.
        [, $token] = $this->makeTenantUser($tenant, ['inventory.view', 'purchase_order.view', 'tire.view', 'dashboard.finance.view']);
        $response = $this->getJson('/api/v1/app/dashboard', $this->authHeaders($token))->assertOk();
        $data = $response->json('data');

        $this->assertSame('50.00', $data['inventory_total_value']); // 5 on_hand * 10 unit_cost
        $this->assertSame(1, $data['inventory_low_stock']);
        $this->assertSame(0, $data['inventory_out_of_stock']);
        $this->assertSame(1, $data['purchase_orders_open']);
        $this->assertSame(1, $data['goods_receipts_pending']);
        $this->assertSame(1, $data['tires_in_use']);
        // Retired from the payload: orphaned Warranty, retired reservations.
        $this->assertArrayNotHasKey('warranty_claims_active', $data);
        $this->assertArrayNotHasKey('inventory_reserved_stock', $data);

        // Without permissions nothing but the organization counts is returned.
        [, $bare] = $this->makeTenantUser($tenant, []);
        $bareData = $this->getJson('/api/v1/app/dashboard', $this->authHeaders($bare))->assertOk()->json('data');
        $this->assertArrayNotHasKey('inventory_total_value', $bareData);
        $this->assertArrayNotHasKey('inventory_low_stock', $bareData);
        $this->assertArrayNotHasKey('tires_in_use', $bareData);
    }
}
