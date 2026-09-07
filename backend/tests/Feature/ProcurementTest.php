<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Procurement\Models\Rfq;
use App\Domain\Procurement\Models\VendorQuotation;
use Tests\TestCase;

class ProcurementTest extends TestCase
{
    private function setUpScenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'PROC-'.\Illuminate\Support\Str::random(4)]);
        $this->grantModule($tenant, 'INVENTORY');
        $this->grantModule($tenant, 'PROCUREMENT');
        $this->grantModule($tenant, 'PARTNER');
        $branch = $this->makeBranch($tenant);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        $product = $this->makeProduct($tenant);
        $vendor = $this->makePartner($tenant, ['partner_type' => 'SPARE_PART_SUPPLIER']);

        return [$tenant, $warehouse, $product, $vendor];
    }

    private function fullPermissions(): array
    {
        return [
            'purchase_request.view', 'purchase_request.create', 'purchase_request.submit', 'purchase_request.approve',
            'rfq.view', 'rfq.manage',
            'quotation.view', 'quotation.manage', 'quotation.select',
            'purchase_order.view', 'purchase_order.create', 'purchase_order.approve', 'purchase_order.issue',
            'goods_receipt.view', 'goods_receipt.create', 'goods_receipt.post',
            'partner.view',
        ];
    }

    public function test_purchase_request_full_lifecycle(): void
    {
        [$tenant, $warehouse, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/purchase-requests', [
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'requested_quantity' => 10, 'estimated_unit_price' => 5]],
        ], $headers)->assertStatus(201);
        $pr = PurchaseRequest::query()->findOrFail($create->json('data.id'));

        $this->postJson("/api/v1/app/purchase-requests/{$pr->id}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/purchase-requests/{$pr->id}/review", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/purchase-requests/{$pr->id}/approve", [], $headers)->assertOk()
            ->assertJsonPath('data.status', 'APPROVED');
    }

    public function test_purchase_request_invalid_transition_is_rejected(): void
    {
        [$tenant, $warehouse, $product] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/purchase-requests', [
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'requested_quantity' => 10]],
        ], $headers)->assertStatus(201);
        $pr = PurchaseRequest::query()->findOrFail($create->json('data.id'));

        $this->postJson("/api/v1/app/purchase-requests/{$pr->id}/approve", [], $headers)->assertStatus(422);
    }

    public function test_rfq_invite_vendors_and_quotation_comparison_picks_lowest_total(): void
    {
        [$tenant, $warehouse, $product, $vendorA] = $this->setUpScenario();
        $vendorB = $this->makePartner($tenant, ['partner_type' => 'SPARE_PART_SUPPLIER', 'code' => 'VEN-B']);
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/rfqs', [
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
        ], $headers)->assertStatus(201);
        $rfq = Rfq::query()->findOrFail($create->json('data.id'));

        $this->postJson("/api/v1/app/rfqs/{$rfq->id}/vendors", [
            'partner_ids' => [$vendorA->id, $vendorB->id],
        ], $headers)->assertOk();

        $quoteA = $this->postJson("/api/v1/app/rfqs/{$rfq->id}/quotations", [
            'partner_id' => $vendorA->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 12, 'discount_percent' => 0, 'tax_percent' => 10]],
        ], $headers)->assertStatus(201);
        $this->assertSame(132.0, (float) $quoteA->json('data.total'));

        $quoteB = $this->postJson("/api/v1/app/rfqs/{$rfq->id}/quotations", [
            'partner_id' => $vendorB->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 10, 'discount_percent' => 0, 'tax_percent' => 10]],
        ], $headers)->assertStatus(201);
        $this->assertSame(110.0, (float) $quoteB->json('data.total'));

        $compare = $this->getJson("/api/v1/app/rfqs/{$rfq->id}/compare", $headers)->assertOk();
        $ranked = $compare->json('data');
        $this->assertSame($vendorB->id, $ranked[0]['partner']['id']);
        $this->assertSame(110.0, (float) $ranked[0]['total']);
    }

    public function test_quotation_total_is_server_calculated_not_client_trusted(): void
    {
        [$tenant, $warehouse, $product, $vendor] = $this->setUpScenario();
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/rfqs', [
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'quantity' => 5]],
        ], $headers)->assertStatus(201);
        $rfq = Rfq::query()->findOrFail($create->json('data.id'));

        $response = $this->postJson("/api/v1/app/rfqs/{$rfq->id}/quotations", [
            'partner_id' => $vendor->id,
            'total' => 1, // client-provided total must be ignored
            'items' => [['product_id' => $product->id, 'quantity' => 5, 'unit_price' => 20, 'discount_percent' => 10, 'tax_percent' => 0]],
        ], $headers)->assertStatus(201);

        // 5 * 20 = 100, less 10% discount = 90
        $this->assertSame(90.0, (float) $response->json('data.total'));
    }

    private function createSelectedQuotation(array $tenantAndDeps, string $token): VendorQuotation
    {
        [$tenant, $warehouse, $product, $vendor] = $tenantAndDeps;
        $headers = $this->authHeaders($token);

        $create = $this->postJson('/api/v1/app/rfqs', [
            'warehouse_id' => $warehouse->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10]],
        ], $headers)->assertStatus(201);
        $rfq = Rfq::query()->findOrFail($create->json('data.id'));
        $this->postJson("/api/v1/app/rfqs/{$rfq->id}/vendors", ['partner_ids' => [$vendor->id]], $headers)->assertOk();

        $quoteResponse = $this->postJson("/api/v1/app/rfqs/{$rfq->id}/quotations", [
            'partner_id' => $vendor->id,
            'items' => [['product_id' => $product->id, 'quantity' => 10, 'unit_price' => 8, 'discount_percent' => 0, 'tax_percent' => 0]],
        ], $headers)->assertStatus(201);
        $quotation = VendorQuotation::query()->findOrFail($quoteResponse->json('data.id'));

        $this->postJson("/api/v1/app/quotations/{$quotation->id}/select", [], $headers)->assertOk();

        return $quotation->fresh();
    }

    public function test_purchase_order_lifecycle_with_partial_receipt_and_over_receipt_rejection(): void
    {
        $scenario = $this->setUpScenario();
        [$tenant, $warehouse, $product, $vendor] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $quotation = $this->createSelectedQuotation($scenario, $token);

        $poResponse = $this->postJson("/api/v1/app/quotations/{$quotation->id}/purchase-order", [
            'delivery_warehouse_id' => $warehouse->id,
        ], $headers)->assertStatus(201);
        $po = PurchaseOrder::query()->findOrFail($poResponse->json('data.id'));
        $this->assertSame(80.0, (float) $po->total);

        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/submit", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/approve", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/issue", [], $headers)->assertOk();

        $item = $po->items()->first();

        // Partial receipt: 6 of 10.
        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/goods-receipts", [
            'lines' => [['purchase_order_item_id' => $item->id, 'quantity_accepted' => 6]],
        ], $headers)->assertStatus(201);

        $po->refresh();
        $this->assertSame('PARTIALLY_RECEIVED', $po->status);
        $stock = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->first();
        $this->assertSame(6.0, (float) $stock->quantity_on_hand);

        // Over-receipt beyond remaining (4 left) must be rejected.
        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/goods-receipts", [
            'lines' => [['purchase_order_item_id' => $item->id, 'quantity_accepted' => 5]],
        ], $headers)->assertStatus(422);

        // Remaining 4 completes the PO.
        $this->postJson("/api/v1/app/purchase-orders/{$po->id}/goods-receipts", [
            'lines' => [['purchase_order_item_id' => $item->id, 'quantity_accepted' => 4]],
        ], $headers)->assertStatus(201);

        $po->refresh();
        $this->assertSame('RECEIVED', $po->status);
        $stock->refresh();
        $this->assertSame(10.0, (float) $stock->quantity_on_hand);
    }

    public function test_duplicate_purchase_order_conversion_from_same_quotation_is_rejected(): void
    {
        $scenario = $this->setUpScenario();
        [$tenant, $warehouse] = $scenario;
        [, $token] = $this->makeTenantUser($tenant, $this->fullPermissions());
        $headers = $this->authHeaders($token);

        $quotation = $this->createSelectedQuotation($scenario, $token);

        $this->postJson("/api/v1/app/quotations/{$quotation->id}/purchase-order", [
            'delivery_warehouse_id' => $warehouse->id,
        ], $headers)->assertStatus(201);

        $this->postJson("/api/v1/app/quotations/{$quotation->id}/purchase-order", [
            'delivery_warehouse_id' => $warehouse->id,
        ], $headers)->assertStatus(422);

        $this->assertSame(1, \App\Domain\Procurement\Models\PurchaseOrder::query()->where('vendor_quotation_id', $quotation->id)->count());
    }

    public function test_partner_records_are_tenant_isolated(): void
    {
        [$tenant, , , $vendor] = $this->setUpScenario();
        $otherTenant = $this->makeTenant(['code' => 'PROCB-'.\Illuminate\Support\Str::random(4)]);
        $this->grantModule($otherTenant, 'PARTNER');
        [, $otherToken] = $this->makeTenantUser($otherTenant, ['partner.view']);

        $this->getJson("/api/v1/app/partners/{$vendor->id}", $this->authHeaders($otherToken))->assertStatus(404);
    }
}
