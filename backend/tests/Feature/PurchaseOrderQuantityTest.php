<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\StockMovement;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Remaining Receivable Qty across Goods Receipt, Return to Vendor, Redelivery and Refund (owner rule):
 * Remaining = Ordered − Received − Returned + Reopened (Redelivery Requests and rejected refunds),
 * accepted refunds change nothing more. Mandatory case: 24 ordered, 18 received in total (11 + the
 * redelivered 2 + 5), returns 2 + 5 redelivered and 5 refunded → Remaining 1.
 */
class PurchaseOrderQuantityTest extends TestCase
{
    private const PERMISSIONS = [
        'purchase_order.view', 'goods_receipt.view', 'goods_receipt.post', 'vendor_invoice.view',
        'purchase_return.create', 'purchase_return.decide', 'purchase_return.receive_redelivery',
    ];

    private function scenario(int $ordered = 24): array
    {
        $tenant = $this->makeTenant(['code' => 'POQ-'.Str::random(4)]);
        foreach (['INVENTORY', 'PROCUREMENT', 'PARTNER'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $warehouse = $this->makeWarehouse($tenant, $this->makeBranch($tenant));
        $product = $this->makeProduct($tenant, null, null, ['name' => 'Engine Oil Filter']);
        $vendor = $this->makePartner($tenant, ['name' => 'PT Filter Jaya']);
        $po = PurchaseOrder::query()->create([
            'tenant_id' => $tenant->id, 'po_number' => 'PO/Q/'.Str::random(6), 'partner_id' => $vendor->id, 'delivery_warehouse_id' => $warehouse->id,
            'status' => 'ISSUED', 'order_date' => '2026-10-01', 'subtotal' => $ordered * 85000, 'tax_total' => 0, 'freight_cost' => 0, 'total' => $ordered * 85000,
        ]);
        $line = PurchaseOrderItem::query()->create(['purchase_order_id' => $po->id, 'product_id' => $product->id, 'quantity_ordered' => $ordered, 'unit_price' => 85000, 'line_total' => $ordered * 85000]);
        [, $token] = $this->makeTenantUser($tenant, self::PERMISSIONS);

        return ['po' => $po, 'line' => $line, 'warehouse' => $warehouse, 'product' => $product, 'headers' => $this->authHeaders($token)];
    }

    private function receive(array $s, float $qty)
    {
        return $this->postJson("/api/v1/app/purchase-orders/{$s['po']->id}/goods-receipts", [
            'lines' => [['purchase_order_item_id' => $s['line']->id, 'quantity_accepted' => $qty]], 'invoice_mode' => 'NEW',
            'vendor_invoice_number' => 'INV-'.Str::random(6), 'vendor_invoice_date' => '2026-10-02', 'amount' => '1000', 'terms_of_payment_days' => '30',
        ], $s['headers']);
    }

    private function returnGoods(array $s, string $option, float $qty): string
    {
        return $this->postJson("/api/v1/app/purchase-orders/{$s['po']->id}/returns", [
            'return_option' => $option, 'items' => [['purchase_order_item_id' => $s['line']->id, 'quantity' => $qty]],
        ], $s['headers'])->assertStatus(201)->json('data.id');
    }

    /** Return for redelivery → print → Receive Redelivery → Goods Receipt of the replacement. */
    private function redeliveryCycle(array $s, float $qty): void
    {
        $id = $this->returnGoods($s, 'REDELIVERY', $qty);
        $this->get("/api/v1/app/purchase-returns/{$id}/print", $s['headers'])->assertOk();
        $this->postJson("/api/v1/app/purchase-returns/{$id}/receive-redelivery", [], $s['headers'])->assertOk();
        $this->receive($s, $qty)->assertStatus(201);
    }

    private function quantities(array $s): array
    {
        return $this->getJson("/api/v1/app/purchase-orders/{$s['po']->id}", $s['headers'])->assertOk()->json("data.return_summary.items.{$s['line']->id}");
    }

    private function remaining(array $s): string
    {
        return $this->quantities($s)['remaining_receivable_quantity'];
    }

    /** The owner's mandatory case, built through the real flow. */
    private function ownerCase(): array
    {
        $s = $this->scenario();
        $this->receive($s, 11)->assertStatus(201);
        $this->redeliveryCycle($s, 2);
        $this->redeliveryCycle($s, 5);
        $refund = $this->returnGoods($s, 'REFUND', 5);

        return [$s, $refund];
    }

    public function test_simple_partial_receipt(): void
    {
        $s = $this->scenario();
        $this->receive($s, 18)->assertStatus(201);
        $this->assertSame('6.0000', $this->remaining($s));
    }

    public function test_return_reduces_remaining_once_and_redelivery_reopens_it_once(): void
    {
        $s = $this->scenario();
        $this->receive($s, 18)->assertStatus(201);
        $this->returnGoods($s, 'REFUND', 2); // refund requested: removed from Remaining, not re-opened
        $this->assertSame('4.0000', $this->remaining($s));

        $t = $this->scenario();
        $this->receive($t, 18)->assertStatus(201);
        $this->returnGoods($t, 'REDELIVERY', 2); // − 2 returned + 2 re-opened
        $this->assertSame('6.0000', $this->remaining($t));
        $this->assertSame('2.0000', $this->quantities($t)['reopened_for_redelivery_quantity']);
    }

    public function test_mandatory_owner_case_remaining_is_one(): void
    {
        [$s, $refund] = $this->ownerCase();
        $q = $this->quantities($s);
        // 24 − 18 − 2 + 2 − 5 + 5 − 5 = 1 (refund requested)
        $this->assertSame('24.0000', $q['ordered_quantity']);
        $this->assertSame('18.0000', $q['gross_received_quantity']);
        $this->assertSame('12.0000', $q['returned_quantity']);
        $this->assertSame('7.0000', $q['reopened_for_redelivery_quantity']);
        $this->assertSame('5.0000', $q['refund_requested_quantity']);
        $this->assertSame('6.0000', $q['net_held_quantity']);
        $this->assertSame('1.0000', $q['remaining_receivable_quantity']);
        $this->assertSame('1', $q['remaining_quantity'], 'compatibility field (trimmed format) equals the canonical one');

        // Accepted refund: no second deduction.
        $this->postJson("/api/v1/app/purchase-returns/{$refund}/accept", [], $s['headers'])->assertOk();
        $q = $this->quantities($s);
        $this->assertSame('1.0000', $q['remaining_receivable_quantity']);
        $this->assertSame('5.0000', $q['accepted_refund_quantity']);
        $this->assertSame('0.0000', $q['refund_requested_quantity']);
    }

    public function test_post_goods_receipt_cannot_exceed_remaining(): void
    {
        [$s, $refund] = $this->ownerCase();
        $this->postJson("/api/v1/app/purchase-returns/{$refund}/accept", [], $s['headers'])->assertOk();
        $receipts = GoodsReceipt::query()->count();
        $movements = StockMovement::query()->count();
        $onHand = WarehouseStock::query()->where('warehouse_id', $s['warehouse']->id)->value('quantity_on_hand');

        $this->receive($s, 2)->assertStatus(422)->assertJsonFragment(['message' => 'Receiving 2 exceeds the Remaining Receivable Qty of this line (1).']);
        $this->receive($s, 0)->assertStatus(422);
        $this->assertSame($receipts, GoodsReceipt::query()->count(), 'no Goods Receipt created');
        $this->assertSame($movements, StockMovement::query()->count(), 'no inventory movement');
        $this->assertEquals($onHand, WarehouseStock::query()->where('warehouse_id', $s['warehouse']->id)->value('quantity_on_hand'));

        $this->receive($s, 1)->assertStatus(201);
        $this->assertSame('0.0000', $this->remaining($s));
        $this->assertSame('RECEIVED', $s['po']->fresh()->status);
        $this->receive($s, 1)->assertStatus(422); // a second post of the same last unit fails
    }

    public function test_rejected_refund_reopens_the_quantity_for_redelivery(): void
    {
        [$s, $refund] = $this->ownerCase();
        $this->postJson("/api/v1/app/purchase-returns/{$refund}/reject", [], $s['headers'])->assertOk();
        $q = $this->quantities($s);
        $this->assertSame('6.0000', $q['remaining_receivable_quantity'], 'rejected refund is not counted as refunded');
        $this->assertSame('0.0000', $q['accepted_refund_quantity']);
        $this->assertSame('12.0000', $q['reopened_for_redelivery_quantity']);

        $this->postJson("/api/v1/app/purchase-returns/{$refund}/receive-redelivery", [], $s['headers'])->assertOk();
        $this->receive($s, 5)->assertStatus(201); // the replacement
        $this->assertSame('1.0000', $this->remaining($s));
    }

    public function test_refund_request_cannot_take_remaining_below_zero(): void
    {
        $s = $this->scenario();
        $this->receive($s, 22)->assertStatus(201); // remaining 2
        $this->postJson("/api/v1/app/purchase-orders/{$s['po']->id}/returns", [
            'return_option' => 'REFUND', 'items' => [['purchase_order_item_id' => $s['line']->id, 'quantity' => 3]],
        ], $s['headers'])->assertStatus(422);
        $this->returnGoods($s, 'REDELIVERY', 3); // a redelivery re-opens what it returns: allowed
        $this->assertSame('2.0000', $this->remaining($s));
    }

    public function test_database_rejects_over_receipt_written_around_the_service(): void
    {
        $s = $this->scenario();
        $this->expectException(QueryException::class);
        DB::table('purchase_order_items')->where('id', $s['line']->id)->update(['quantity_received' => 25]);
    }
}
