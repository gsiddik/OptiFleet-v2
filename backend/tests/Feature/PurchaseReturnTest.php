<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Domain\Procurement\Models\PurchaseReturn;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Purchase Order Return to Vendor: Return Orders per PO line for a refund or a redelivery, the
 * vendor's decision, Print Return Order, Receive Redelivery and the Goods Receipt gating.
 */
class PurchaseReturnTest extends TestCase
{
    private const PERMISSIONS = [
        'purchase_order.view', 'goods_receipt.view', 'goods_receipt.post', 'vendor_invoice.view',
        'purchase_return.create', 'purchase_return.decide', 'purchase_return.receive_redelivery',
    ];

    /**
     * PO: Oil Filter 10 @ 1,000 (10% discount, 11% tax) + Air Filter 5 @ 500; received 6 + 2,
     * so it is PARTIALLY_RECEIVED.
     */
    private function scenario(array $permissions = self::PERMISSIONS): array
    {
        $tenant = $this->makeTenant(['code' => 'PRT-'.Str::random(4)]);
        foreach (['INVENTORY', 'PROCUREMENT', 'PARTNER'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $warehouse = $this->makeWarehouse($tenant, $this->makeBranch($tenant));
        $oil = $this->makeProduct($tenant, null, null, ['name' => 'Oil Filter']);
        $air = $this->makeProduct($tenant, null, null, ['name' => 'Air Filter']);
        $vendor = $this->makePartner($tenant, ['name' => 'PT Vendor Retur', 'address' => 'Jl. Industri 1', 'contact_phone' => '0812000111']);
        $po = PurchaseOrder::query()->create([
            'tenant_id' => $tenant->id, 'po_number' => 'PO/T/'.Str::random(6), 'partner_id' => $vendor->id, 'delivery_warehouse_id' => $warehouse->id,
            'status' => 'ISSUED', 'order_date' => '2026-10-01', 'subtotal' => 11500, 'tax_total' => 990, 'freight_cost' => 0, 'total' => 12490,
        ]);
        PurchaseOrderItem::query()->create(['purchase_order_id' => $po->id, 'product_id' => $oil->id, 'quantity_ordered' => 10, 'unit_price' => 1000, 'discount_percent' => 10, 'tax_percent' => 11, 'line_total' => 9990]);
        PurchaseOrderItem::query()->create(['purchase_order_id' => $po->id, 'product_id' => $air->id, 'quantity_ordered' => 5, 'unit_price' => 500, 'line_total' => 2500]);
        [, $token] = $this->makeTenantUser($tenant, $permissions);
        $headers = $this->authHeaders($token);
        $s = compact('tenant', 'warehouse', 'oil', 'air', 'vendor', 'po', 'headers');
        $this->receive($s, ['Oil Filter' => 6, 'Air Filter' => 2])->assertStatus(201);
        $this->assertSame('PARTIALLY_RECEIVED', $po->fresh()->status);

        return $s;
    }

    private function line(array $s, string $name): PurchaseOrderItem
    {
        return $s['po']->items()->where('product_id', $s[$name === 'Oil Filter' ? 'oil' : 'air']->id)->firstOrFail();
    }

    private function receive(array $s, array $quantities)
    {
        $lines = collect($quantities)->map(fn ($qty, $name) => ['purchase_order_item_id' => $this->line($s, $name)->id, 'quantity_accepted' => $qty])->values()->all();

        return $this->postJson("/api/v1/app/purchase-orders/{$s['po']->id}/goods-receipts", [
            'lines' => $lines, 'invoice_mode' => 'NEW', 'vendor_invoice_number' => 'INV-'.Str::random(5),
            'vendor_invoice_date' => '2026-10-02', 'amount' => '1000', 'terms_of_payment_days' => '30',
        ], $s['headers']);
    }

    private function returnGoods(array $s, string $option, array $quantities)
    {
        return $this->postJson("/api/v1/app/purchase-orders/{$s['po']->id}/returns", [
            'return_option' => $option,
            'items' => collect($quantities)->map(fn ($qty, $name) => ['purchase_order_item_id' => $this->line($s, $name)->id, 'quantity' => $qty])->values()->all(),
        ], $s['headers']);
    }

    private function show(array $s): array
    {
        return $this->getJson("/api/v1/app/purchase-orders/{$s['po']->id}", $s['headers'])->assertOk()->json('data');
    }

    private function onHand(array $s, string $product): float
    {
        return (float) WarehouseStock::query()->where('warehouse_id', $s['warehouse']->id)->where('product_id', $s[$product]->id)->value('quantity_on_hand');
    }

    public function test_return_to_vendor_is_offered_and_accepted_only_for_a_partially_received_po(): void
    {
        $s = $this->scenario();
        $data = $this->show($s);
        $this->assertTrue($data['return_summary']['can_return']);
        $this->assertSame([], $data['returns'], 'no Return History before a Return Order');

        foreach (['ISSUED', 'RECEIVED', 'CLOSED'] as $status) {
            $s['po']->fresh()->update(['status' => $status]);
            $this->assertFalse($this->show($s)['return_summary']['can_return']);
            $this->returnGoods($s, 'REFUND', ['Oil Filter' => 1])->assertStatus(422);
        }
        $this->assertSame(0, PurchaseReturn::query()->count());
    }

    public function test_returned_quantities_are_validated_per_po_line(): void
    {
        $s = $this->scenario();
        $this->returnGoods($s, 'REFUND', ['Oil Filter' => 7])->assertStatus(422); // only 6 received
        $this->returnGoods($s, 'REFUND', ['Oil Filter' => -1])->assertStatus(422);
        $this->returnGoods($s, 'REFUND', ['Oil Filter' => 0])->assertStatus(422);
        $this->returnGoods($s, 'EXCHANGE', ['Oil Filter' => 1])->assertStatus(422);
        $this->postJson("/api/v1/app/purchase-orders/{$s['po']->id}/returns", ['return_option' => 'REFUND', 'items' => [['purchase_order_item_id' => (string) Str::uuid(), 'quantity' => 1]]], $s['headers'])->assertStatus(422);
        $this->assertSame(0, PurchaseReturn::query()->count());
        $this->assertSame(6.0, $this->onHand($s, 'oil'), 'nothing moved');
    }

    public function test_refund_request_accepted_by_vendor_records_the_refunded_amount_once(): void
    {
        $s = $this->scenario();
        $created = $this->returnGoods($s, 'REFUND', ['Oil Filter' => 2, 'Air Filter' => 1])->assertStatus(201)->json('data');
        $this->assertStringStartsWith('RO/', $created['return_number']);
        $this->assertSame('REFUND_REQUESTED', $created['status']);
        $this->assertSame([4.0, 1.0], [$this->onHand($s, 'oil'), $this->onHand($s, 'air')], 'returned goods left the warehouse');
        $this->assertSame(2, StockMovement::query()->where('movement_type', 'RETURN_TO_VENDOR')->count());

        $data = $this->show($s);
        $this->assertSame($created['id'], $data['return_summary']['open_return_id']);
        $this->assertFalse($data['return_summary']['can_return']);
        $this->assertFalse($data['return_summary']['goods_receipt_blocked'], 'a refund request does not block Goods Receipt');
        // Owner rule: the return reduces Remaining once (10 − 6 − 2) and a refund does not re-open it.
        $this->assertSame('2', $data['return_summary']['items'][$this->line($s, 'Oil Filter')->id]['remaining_quantity']);
        $this->assertSame('4', $data['return_summary']['items'][$this->line($s, 'Oil Filter')->id]['returnable_quantity']);
        $this->assertNull($data['returns'][0]['refunded_amount']);
        $this->returnGoods($s, 'REFUND', ['Oil Filter' => 1])->assertStatus(422); // one open Return Order at a time

        $accepted = $this->postJson("/api/v1/app/purchase-returns/{$created['id']}/accept", [], $s['headers'])->assertOk()->json('data');
        // Oil: 2 × 1,000 − 10% = 1,800 + 11% tax = 1,998; Air: 1 × 500 = 500.
        $this->assertSame(['REFUND_ACCEPTED', '2498.0000', 'ACCEPTED'], [$accepted['status'], $accepted['refunded_amount'], $accepted['vendor_decision']]);
        $this->postJson("/api/v1/app/purchase-returns/{$created['id']}/accept", [], $s['headers'])->assertStatus(422);
        $this->postJson("/api/v1/app/purchase-returns/{$created['id']}/reject", [], $s['headers'])->assertStatus(422);
        $this->assertSame(['CREATED', 'VENDOR_ACCEPTED'], collect($this->show($s)['returns'][0]['events'])->pluck('event')->all());

        // The PO can return again; both Return Orders stay in the history.
        $this->returnGoods($s, 'REDELIVERY', ['Air Filter' => 1])->assertStatus(201);
        $history = $this->show($s)['returns'];
        $this->assertCount(2, $history);
        $this->assertSame(['REFUND', 'REDELIVERY'], array_column($history, 'return_option'));
        $this->returnGoods($s, 'REFUND', ['Air Filter' => 1])->assertStatus(422); // nothing left to return of Air Filter / open return
    }

    public function test_refund_rejected_by_vendor_becomes_a_redelivery_and_keeps_its_history(): void
    {
        $s = $this->scenario();
        $id = $this->returnGoods($s, 'REFUND', ['Oil Filter' => 2])->assertStatus(201)->json('data.id');
        $rejected = $this->postJson("/api/v1/app/purchase-returns/{$id}/reject", ['note' => 'Vendor replaces the goods'], $s['headers'])->assertOk()->json('data');
        $this->assertSame(['REDELIVERY_PENDING', 'REFUND', 'REJECTED'], [$rejected['status'], $rejected['return_option'], $rejected['vendor_decision']]);
        $this->assertSame(['CREATED', 'VENDOR_REJECTED', 'REDELIVERY_EXPECTED'], collect($rejected['events'])->pluck('event')->all());
        $this->postJson("/api/v1/app/purchase-returns/{$id}/accept", [], $s['headers'])->assertStatus(422);

        $data = $this->show($s);
        $this->assertTrue($data['return_summary']['goods_receipt_blocked']);
        $this->receive($s, ['Oil Filter' => 1])->assertStatus(422);
        // Owner rule: 10 − 6 − 2 (return) + 2 (re-opened by the rejected refund) = 4.
        $this->assertSame('4', $data['return_summary']['items'][$this->line($s, 'Oil Filter')->id]['remaining_quantity'], 'the returned 2 are re-opened once');

        // Rejected → Receive Redelivery is available at once (no print needed).
        $this->postJson("/api/v1/app/purchase-returns/{$id}/receive-redelivery", [], $s['headers'])->assertOk()->assertJsonPath('data.status', 'REDELIVERY_RECEIVED');
        $this->assertFalse($this->show($s)['return_summary']['goods_receipt_blocked']);
        $this->receive($s, ['Oil Filter' => 5, 'Air Filter' => 3])->assertStatus(422); // more than Remaining (4)
        $this->receive($s, ['Oil Filter' => 4, 'Air Filter' => 3])->assertStatus(201);
        $this->assertSame('RECEIVED', $s['po']->fresh()->status);
        $this->assertSame(8.0, $this->onHand($s, 'oil'));
    }

    public function test_redelivery_request_needs_the_printed_return_order_before_it_is_received(): void
    {
        $s = $this->scenario();
        $id = $this->returnGoods($s, 'REDELIVERY', ['Oil Filter' => 2])->assertStatus(201)->json('data.id');
        $this->assertTrue($this->show($s)['return_summary']['goods_receipt_blocked']);
        $this->receive($s, ['Air Filter' => 1])->assertStatus(422);
        $this->postJson("/api/v1/app/purchase-returns/{$id}/receive-redelivery", [], $s['headers'])->assertStatus(422);
        $this->postJson("/api/v1/app/purchase-returns/{$id}/accept", [], $s['headers'])->assertStatus(422);

        $pdf = $this->get("/api/v1/app/purchase-returns/{$id}/print", $s['headers'])->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $return = PurchaseReturn::query()->findOrFail($id);
        $this->assertSame('REDELIVERY_READY', $return->status);
        $this->assertNotNull($return->printed_at);

        $this->postJson("/api/v1/app/purchase-returns/{$id}/receive-redelivery", [], $s['headers'])->assertOk();
        $this->postJson("/api/v1/app/purchase-returns/{$id}/receive-redelivery", [], $s['headers'])->assertStatus(422);
        $data = $this->show($s);
        $this->assertFalse($data['return_summary']['goods_receipt_blocked']);
        $this->assertTrue($data['return_summary']['can_return']);
        $this->assertSame(['CREATED', 'PRINTED', 'REDELIVERY_RECEIVED'], collect($data['returns'][0]['events'])->pluck('event')->all());
        $this->assertNull($data['returns'][0]['refunded_amount'], 'a redelivery has no refunded amount');
        // Owner rule: − 2 returned + 2 re-opened → Remaining stays 4; the redelivered goods come in by Goods Receipt.
        $this->receive($s, ['Oil Filter' => 5])->assertStatus(422);
        $this->receive($s, ['Oil Filter' => 4])->assertStatus(201);
    }

    public function test_return_actions_are_permission_and_tenant_guarded(): void
    {
        $s = $this->scenario();
        [, $viewerToken] = $this->makeTenantUser($s['tenant'], ['purchase_order.view', 'goods_receipt.post']);
        $viewer = $this->authHeaders($viewerToken);
        $item = $this->line($s, 'Oil Filter')->id;
        $this->postJson("/api/v1/app/purchase-orders/{$s['po']->id}/returns", ['return_option' => 'REFUND', 'items' => [['purchase_order_item_id' => $item, 'quantity' => 1]]], $viewer)->assertForbidden();
        $id = $this->returnGoods($s, 'REFUND', ['Oil Filter' => 1])->json('data.id');
        $this->postJson("/api/v1/app/purchase-returns/{$id}/accept", [], $viewer)->assertForbidden();
        $this->postJson("/api/v1/app/purchase-returns/{$id}/reject", [], $viewer)->assertForbidden();

        $other = $this->makeTenant(['code' => 'PRX-'.Str::random(4)]);
        $this->grantModule($other, 'PROCUREMENT');
        [, $otherToken] = $this->makeTenantUser($other, self::PERMISSIONS);
        $this->postJson("/api/v1/app/purchase-returns/{$id}/accept", [], $this->authHeaders($otherToken))->assertNotFound();
        $this->assertSame('REFUND_REQUESTED', PurchaseReturn::query()->withoutGlobalScopes()->findOrFail($id)->status);
    }
}
