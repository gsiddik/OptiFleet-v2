<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryException;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Inventory\Services\StockOpnameService;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    private function setUpWarehouseAndProduct(): array
    {
        $tenant = $this->makeTenant(['code' => 'INV-'.\Illuminate\Support\Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $branch = $this->makeBranch($tenant);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        $product = $this->makeProduct($tenant);

        return [$tenant, $warehouse, $product];
    }

    public function test_warehouse_stock_access_is_warehouse_scoped(): void
    {
        [$tenant, $warehouseA, $product] = $this->setUpWarehouseAndProduct();
        $warehouseB = $this->makeWarehouse($tenant);

        app(InventoryService::class)->receive($warehouseA, $product, 10, 5, 'OPENING', null, null, null);
        app(InventoryService::class)->receive($warehouseB, $product, 10, 5, 'OPENING', null, null, null);

        [, $token] = $this->makeTenantUser($tenant, ['inventory.view'], ['WAREHOUSE' => $warehouseA->id]);

        $list = $this->getJson('/api/v1/app/inventory', $this->authHeaders($token))->assertOk();
        $warehouseIds = collect($list->json('data'))->pluck('warehouse_id');
        $this->assertTrue($warehouseIds->contains($warehouseA->id));
        $this->assertFalse($warehouseIds->contains($warehouseB->id));
    }

    public function test_stock_reservation_reduces_available_quantity(): void
    {
        [$tenant, $warehouse, $product] = $this->setUpWarehouseAndProduct();
        app(InventoryService::class)->receive($warehouse, $product, 10, 5, 'OPENING', null, null, null);

        $result = app(InventoryService::class)->reserve($warehouse, $product, 4, null, null, null);

        $this->assertSame(4.0, $result['reserved']);
        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(10.0, (float) $stock->quantity_on_hand);
        $this->assertSame(4.0, (float) $stock->quantity_reserved);
        $this->assertSame(6.0, $stock->quantityAvailable());
    }

    public function test_over_reservation_is_partially_fulfilled_not_negative(): void
    {
        [, $warehouse, $product] = $this->setUpWarehouseAndProduct();
        app(InventoryService::class)->receive($warehouse, $product, 5, 5, 'OPENING', null, null, null);

        $result = app(InventoryService::class)->reserve($warehouse, $product, 8, null, null, null);

        $this->assertSame(5.0, $result['reserved']);
        $this->assertSame(3.0, $result['shortfall']);
        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(5.0, (float) $stock->quantity_reserved);
        $this->assertGreaterThanOrEqual(0, $stock->quantityAvailable());
    }

    public function test_reservation_release_restores_availability(): void
    {
        [, $warehouse, $product] = $this->setUpWarehouseAndProduct();
        app(InventoryService::class)->receive($warehouse, $product, 10, 5, 'OPENING', null, null, null);
        app(InventoryService::class)->reserve($warehouse, $product, 6, null, null, null);

        app(InventoryService::class)->releaseReservation($warehouse, $product, 6, null, null, null);

        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(0.0, (float) $stock->quantity_reserved);
        $this->assertSame(10.0, $stock->quantityAvailable());
    }

    public function test_issue_reduces_on_hand_and_reserved(): void
    {
        [, $warehouse, $product] = $this->setUpWarehouseAndProduct();
        app(InventoryService::class)->receive($warehouse, $product, 10, 5, 'OPENING', null, null, null);
        app(InventoryService::class)->reserve($warehouse, $product, 4, null, null, null);

        $result = app(InventoryService::class)->issue($warehouse, $product, 4, null, null, null);

        $this->assertSame(5.0, $result['unit_cost']);
        $this->assertSame(20.0, $result['total_cost']);
        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(6.0, (float) $stock->quantity_on_hand);
        $this->assertSame(0.0, (float) $stock->quantity_reserved);
    }

    public function test_issue_without_sufficient_stock_is_rejected(): void
    {
        [, $warehouse, $product] = $this->setUpWarehouseAndProduct();
        app(InventoryService::class)->receive($warehouse, $product, 2, 5, 'OPENING', null, null, null);

        $this->expectException(InventoryException::class);
        app(InventoryService::class)->issue($warehouse, $product, 5, null, null, null);
    }

    public function test_return_increases_on_hand(): void
    {
        [, $warehouse, $product] = $this->setUpWarehouseAndProduct();
        app(InventoryService::class)->receive($warehouse, $product, 10, 5, 'OPENING', null, null, null);
        app(InventoryService::class)->issue($warehouse, $product, 4, null, null, null);

        app(InventoryService::class)->returnStock($warehouse, $product, 1, null, null, null);

        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(7.0, (float) $stock->quantity_on_hand);
    }

    public function test_stock_adjustment_requires_permission(): void
    {
        [$tenant, $warehouse, $product] = $this->setUpWarehouseAndProduct();
        app(InventoryService::class)->receive($warehouse, $product, 10, 5, 'OPENING', null, null, null);

        [, $token] = $this->makeTenantUser($tenant, ['inventory.view']); // no inventory.adjust

        $this->postJson('/api/v1/app/inventory/adjust', [
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 2, 'direction' => 'PLUS', 'reason' => 'Found extra stock',
        ], $this->authHeaders($token))->assertStatus(403);
    }

    public function test_stock_adjustment_creates_movement_and_updates_balance(): void
    {
        [$tenant, $warehouse, $product] = $this->setUpWarehouseAndProduct();
        app(InventoryService::class)->receive($warehouse, $product, 10, 5, 'OPENING', null, null, null);
        [, $token] = $this->makeTenantUser($tenant, ['inventory.adjust']);

        $this->postJson('/api/v1/app/inventory/adjust', [
            'warehouse_id' => $warehouse->id, 'product_id' => $product->id, 'quantity' => 3, 'direction' => 'MINUS', 'reason' => 'Damaged in storage',
        ], $this->authHeaders($token))->assertStatus(201);

        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(7.0, (float) $stock->quantity_on_hand);

        $movement = \App\Domain\Inventory\Models\StockMovement::query()->where('warehouse_id', $warehouse->id)->where('movement_type', 'ADJUSTMENT_MINUS')->first();
        $this->assertNotNull($movement);
        $this->assertSame('Damaged in storage', $movement->reason);
    }

    public function test_stock_movement_ledger_reconstructs_balance(): void
    {
        [, $warehouse, $product] = $this->setUpWarehouseAndProduct();
        $inventory = app(InventoryService::class);
        $inventory->receive($warehouse, $product, 10, 5, 'OPENING', null, null, null);
        $inventory->reserve($warehouse, $product, 3, null, null, null);
        $inventory->issue($warehouse, $product, 3, null, null, null);
        $inventory->returnStock($warehouse, $product, 1, null, null, null);

        $movements = \App\Domain\Inventory\Models\StockMovement::query()->where('warehouse_id', $warehouse->id)->get();
        $onHandDelta = 0.0;
        foreach ($movements as $m) {
            $onHandDelta += match ($m->movement_type) {
                'OPENING', 'RECEIPT', 'RETURN', 'TRANSFER_IN', 'ADJUSTMENT_PLUS' => (float) $m->quantity,
                'ISSUE', 'TRANSFER_OUT', 'ADJUSTMENT_MINUS', 'SCRAP' => -(float) $m->quantity,
                default => 0.0,
            };
        }

        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame((float) $stock->quantity_on_hand, $onHandDelta);
    }

    public function test_stock_opname_posting_creates_variance_movement(): void
    {
        [$tenant, $warehouse, $product] = $this->setUpWarehouseAndProduct();
        app(InventoryService::class)->receive($warehouse, $product, 10, 5, 'OPENING', null, null, null);

        $opname = app(StockOpnameService::class)->create($warehouse, null);
        $item = $opname->items()->where('product_id', $product->id)->first();

        app(StockOpnameService::class)->recordCount($item, 8, 'Physical count found 8.');
        $opname = app(StockOpnameService::class)->transition($opname, 'COUNTING');
        $opname = app(StockOpnameService::class)->transition($opname, 'SUBMITTED');
        $opname = app(StockOpnameService::class)->approve($opname, null);
        $posted = app(StockOpnameService::class)->post($opname, null);

        $this->assertSame('POSTED', $posted->status);
        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(8.0, (float) $stock->quantity_on_hand);

        $movement = \App\Domain\Inventory\Models\StockMovement::query()->where('warehouse_id', $warehouse->id)->where('movement_type', 'STOCK_OPNAME')->first();
        $this->assertNotNull($movement);
    }

    public function test_stock_movement_endpoint_is_paginated_and_warehouse_scoped(): void
    {
        [$tenant, $warehouseA, $product] = $this->setUpWarehouseAndProduct();
        $warehouseB = $this->makeWarehouse($tenant);
        app(InventoryService::class)->receive($warehouseA, $product, 10, 5, 'OPENING', null, null, null);
        app(InventoryService::class)->receive($warehouseB, $product, 10, 5, 'OPENING', null, null, null);

        [, $token] = $this->makeTenantUser($tenant, ['inventory.view'], ['WAREHOUSE' => $warehouseA->id]);

        $response = $this->getJson('/api/v1/app/stock-movements', $this->authHeaders($token))->assertOk();
        $warehouseIds = collect($response->json('data'))->pluck('warehouse_id');
        $this->assertTrue($warehouseIds->contains($warehouseA->id));
        $this->assertFalse($warehouseIds->contains($warehouseB->id));
        $this->assertArrayHasKey('current_page', $response->json('meta'));
    }
}
