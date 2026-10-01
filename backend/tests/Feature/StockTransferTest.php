<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryService;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockTransferTest extends TestCase
{
    private function setUpTransferScenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'TRF-'.Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $branch = $this->makeBranch($tenant);
        $from = $this->makeWarehouse($tenant, $branch, null, ['code' => 'WH-FROM']);
        $to = $this->makeWarehouse($tenant, $branch, null, ['code' => 'WH-TO']);
        $product = $this->makeProduct($tenant);

        app(InventoryService::class)->receive($from, $product, 20, 10, 'OPENING', null, null, null);

        return [$tenant, $from, $to, $product];
    }

    private function driveToInTransit(StockTransfer $transfer, string $token): StockTransfer
    {
        $headers = $this->authHeaders($token);
        $this->postJson("/api/v1/app/stock-transfers/{$transfer->id}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/stock-transfers/{$transfer->id}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/stock-transfers/{$transfer->id}/prepare", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/stock-transfers/{$transfer->id}/dispatch", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/stock-transfers/{$transfer->id}/in-transit", [], $headers)->assertOk();

        return $transfer->fresh();
    }

    public function test_full_transfer_lifecycle_moves_stock_from_source_to_destination(): void
    {
        [$tenant, $from, $to, $product] = $this->setUpTransferScenario();
        [$user, $token] = $this->makeTenantUser($tenant, [
            'stock_transfer.view', 'stock_transfer.create', 'stock_transfer.approve', 'stock_transfer.dispatch', 'stock_transfer.receive',
        ]);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/stock-transfers', [
            'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
        ], $headers)->assertStatus(201);
        $transfer = StockTransfer::query()->findOrFail($create->json('data.id'));

        $this->driveToInTransit($transfer, $token);

        $fromStock = WarehouseStock::query()->where('warehouse_id', $from->id)->where('product_id', $product->id)->first();
        $this->assertSame(10.0, (float) $fromStock->quantity_on_hand);

        // G-05: dispatch()/receive() previously recorded only a timestamp — the actor was never persisted.
        $this->assertSame($user->id, $transfer->fresh()->dispatched_by);

        $item = $transfer->items()->first();
        $this->postJson("/api/v1/app/stock-transfers/{$transfer->id}/receive", [
            'receipts' => [['item_id' => $item->id, 'quantity_received' => 10]],
        ], $headers)->assertOk();

        $toStock = WarehouseStock::query()->where('warehouse_id', $to->id)->where('product_id', $product->id)->first();
        $this->assertSame(10.0, (float) $toStock->quantity_on_hand);
        $this->assertSame($user->id, $transfer->fresh()->received_by);

        $this->postJson("/api/v1/app/stock-transfers/{$transfer->id}/complete", [], $headers)->assertOk();
        $this->assertSame('COMPLETED', $transfer->fresh()->status);
    }

    public function test_destination_stock_does_not_increase_before_receipt(): void
    {
        [$tenant, $from, $to, $product] = $this->setUpTransferScenario();
        [, $token] = $this->makeTenantUser($tenant, [
            'stock_transfer.view', 'stock_transfer.create', 'stock_transfer.approve', 'stock_transfer.dispatch', 'stock_transfer.receive',
        ]);

        $create = $this->postJson('/api/v1/app/stock-transfers', [
            'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
        ], $this->authHeaders($token))->assertStatus(201);
        $transfer = StockTransfer::query()->findOrFail($create->json('data.id'));

        $this->driveToInTransit($transfer, $token);

        $toStock = WarehouseStock::query()->where('warehouse_id', $to->id)->where('product_id', $product->id)->first();
        $this->assertNull($toStock);
    }

    public function test_partial_receipt_requires_discrepancy_reason_and_records_damaged_lost(): void
    {
        [$tenant, $from, $to, $product] = $this->setUpTransferScenario();
        [, $token] = $this->makeTenantUser($tenant, [
            'stock_transfer.view', 'stock_transfer.create', 'stock_transfer.approve', 'stock_transfer.dispatch', 'stock_transfer.receive',
        ]);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/stock-transfers', [
            'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
        ], $headers)->assertStatus(201);
        $transfer = StockTransfer::query()->findOrFail($create->json('data.id'));
        $this->driveToInTransit($transfer, $token);
        $item = $transfer->items()->first();

        $this->postJson("/api/v1/app/stock-transfers/{$transfer->id}/receive", [
            'receipts' => [['item_id' => $item->id, 'quantity_received' => 7, 'quantity_damaged' => 1]],
        ], $headers)->assertStatus(422);

        $this->postJson("/api/v1/app/stock-transfers/{$transfer->id}/receive", [
            'receipts' => [['item_id' => $item->id, 'quantity_received' => 7, 'quantity_damaged' => 1, 'quantity_lost' => 1, 'discrepancy_reason' => 'One damaged, one lost in transit']],
        ], $headers)->assertOk();

        $item->refresh();
        $this->assertSame(7.0, (float) $item->quantity_received);
        $this->assertSame(1.0, (float) $item->quantity_damaged);
        $this->assertSame(1.0, (float) $item->quantity_lost);

        $toStock = WarehouseStock::query()->where('warehouse_id', $to->id)->where('product_id', $product->id)->first();
        $this->assertSame(7.0, (float) $toStock->quantity_on_hand);
    }

    public function test_detail_shows_status_history_with_actor_names_and_receipt_discrepancies(): void
    {
        [$tenant, $from, $to, $product] = $this->setUpTransferScenario();
        $all = ['stock_transfer.view', 'stock_transfer.create', 'stock_transfer.approve', 'stock_transfer.dispatch', 'stock_transfer.receive'];
        [$requester, $requesterToken] = $this->makeTenantUser($tenant, $all);
        [$keeper, $keeperToken] = $this->makeTenantUser($tenant, $all);
        $requester->update(['name' => 'Rina Requester']);
        $keeper->update(['name' => 'Kevin Keeper']);

        $id = $this->postJson('/api/v1/app/stock-transfers', [
            'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
        ], $this->authHeaders($requesterToken))->assertStatus(201)->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/app/stock-transfers/{$id}/submit", [], $this->authHeaders($requesterToken))->assertOk();

        $this->app['auth']->forgetGuards();
        $headers = $this->authHeaders($keeperToken);
        foreach (['approve', 'prepare', 'dispatch', 'in-transit'] as $action) {
            $this->postJson("/api/v1/app/stock-transfers/{$id}/{$action}", [], $headers)->assertOk();
        }
        $itemId = StockTransfer::query()->findOrFail($id)->items()->value('id');
        $this->postJson("/api/v1/app/stock-transfers/{$id}/receive", [
            'receipts' => [['item_id' => $itemId, 'quantity_received' => 7, 'quantity_damaged' => 2, 'quantity_lost' => 1, 'discrepancy_reason' => 'Crate crushed']],
        ], $headers)->assertOk();

        $data = $this->getJson("/api/v1/app/stock-transfers/{$id}", $headers)->assertOk()->json('data');

        $history = $data['status_history'];
        $this->assertSame(['DRAFT', 'REQUESTED', 'APPROVED', 'PREPARED', 'DISPATCHED', 'IN_TRANSIT', 'RECEIVED'], array_column($history, 'status'));
        $this->assertSame(['Rina Requester', 'Rina Requester', 'Kevin Keeper', 'Kevin Keeper', 'Kevin Keeper', 'Kevin Keeper', 'Kevin Keeper'], array_column($history, 'by'));
        foreach ($history as $entry) {
            $this->assertNotFalse(strtotime($entry['at']));
        }
        $this->assertSame(['Kevin Keeper', 'Kevin Keeper'], [$data['dispatched_by_name'], $data['received_by_name']]);

        $item = $data['items'][0];
        $this->assertSame(['7.0000', '2.0000', '1.0000', 'Crate crushed'], [$item['quantity_received'], $item['quantity_damaged'], $item['quantity_lost'], $item['discrepancy_reason']]);
    }

    public function test_over_receipt_beyond_quantity_sent_is_rejected(): void
    {
        [$tenant, $from, $to, $product] = $this->setUpTransferScenario();
        [, $token] = $this->makeTenantUser($tenant, [
            'stock_transfer.view', 'stock_transfer.create', 'stock_transfer.approve', 'stock_transfer.dispatch', 'stock_transfer.receive',
        ]);
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/stock-transfers', [
            'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
        ], $headers)->assertStatus(201);
        $transfer = StockTransfer::query()->findOrFail($create->json('data.id'));
        $this->driveToInTransit($transfer, $token);
        $item = $transfer->items()->first();

        $this->postJson("/api/v1/app/stock-transfers/{$transfer->id}/receive", [
            'receipts' => [['item_id' => $item->id, 'quantity_received' => 15]],
        ], $headers)->assertStatus(422);
    }

    public function test_cross_warehouse_scope_denies_transfer_dispatch_and_receipt(): void
    {
        [$tenant, $from, $to, $product] = $this->setUpTransferScenario();
        [, $ownerToken] = $this->makeTenantUser($tenant, [
            'stock_transfer.view', 'stock_transfer.create', 'stock_transfer.approve', 'stock_transfer.dispatch', 'stock_transfer.receive',
        ]);

        $create = $this->postJson('/api/v1/app/stock-transfers', [
            'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
        ], $this->authHeaders($ownerToken))->assertStatus(201);
        $transfer = StockTransfer::query()->findOrFail($create->json('data.id'));

        $otherWarehouse = $this->makeWarehouse($tenant, null, null, ['code' => 'WH-OTHER']);
        [, $scopedToken] = $this->makeTenantUser($tenant, [
            'stock_transfer.view', 'stock_transfer.dispatch',
        ], ['WAREHOUSE' => $otherWarehouse->id]);

        $this->postJson("/api/v1/app/stock-transfers/{$transfer->id}/submit", [], $this->authHeaders($ownerToken))->assertOk();
        $this->postJson("/api/v1/app/stock-transfers/{$transfer->id}/approve", [], $this->authHeaders($ownerToken))->assertOk();
        $this->postJson("/api/v1/app/stock-transfers/{$transfer->id}/prepare", [], $this->authHeaders($ownerToken))->assertOk();

        $this->postJson("/api/v1/app/stock-transfers/{$transfer->id}/dispatch", [], $this->authHeaders($scopedToken))->assertStatus(403);
    }

    public function test_cross_tenant_transfer_access_is_denied(): void
    {
        [$tenant, $from, $to, $product] = $this->setUpTransferScenario();
        [, $token] = $this->makeTenantUser($tenant, ['stock_transfer.view', 'stock_transfer.create']);

        $create = $this->postJson('/api/v1/app/stock-transfers', [
            'from_warehouse_id' => $from->id, 'to_warehouse_id' => $to->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
        ], $this->authHeaders($token))->assertStatus(201);
        $transfer = StockTransfer::query()->findOrFail($create->json('data.id'));

        $otherTenant = $this->makeTenant(['code' => 'TRFB-'.Str::random(4)]);
        $this->grantModule($otherTenant, 'INVENTORY');
        [, $otherToken] = $this->makeTenantUser($otherTenant, ['stock_transfer.view']);

        $this->getJson("/api/v1/app/stock-transfers/{$transfer->id}", $this->authHeaders($otherToken))->assertStatus(404);
    }
}
