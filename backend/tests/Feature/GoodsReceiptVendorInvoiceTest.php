<?php

namespace Tests\Feature;

use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\MaintenancePolicy\Services\WorkingDayService;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Domain\Procurement\Models\VendorInvoiceReference;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Post Goods Receipt → Record Vendor Invoice Reference: each receipt is a new GR posted against
 * a NEW invoice (with its PDF) or the invoice of an earlier receipt of the same PO; receipt,
 * stock, PO status and invoice are saved atomically; history is never overwritten.
 */
class GoodsReceiptVendorInvoiceTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF\n";

    private const PERMISSIONS = ['purchase_order.view', 'goods_receipt.view', 'goods_receipt.post', 'vendor_invoice.view'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'gri');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** An ISSUED PO for 10 units from one vendor. */
    private function scenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'GRI-'.Str::random(4)]);
        foreach (['INVENTORY', 'PROCUREMENT', 'PARTNER'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $warehouse = $this->makeWarehouse($tenant, $this->makeBranch($tenant));
        $product = $this->makeProduct($tenant, null, null, ['name' => 'Oil Filter']);
        $vendor = $this->makePartner($tenant, ['name' => 'PT Vendor Satu']);
        $po = $this->issuedPo($tenant, $vendor, $warehouse, $product);
        [, $token] = $this->makeTenantUser($tenant, self::PERMISSIONS);

        return [$tenant, $warehouse, $product, $vendor, $po, $this->authHeaders($token)];
    }

    private function issuedPo($tenant, $vendor, $warehouse, $product, int $qty = 10): PurchaseOrder
    {
        $po = PurchaseOrder::query()->create([
            'tenant_id' => $tenant->id, 'po_number' => 'PO/T/'.Str::random(6), 'partner_id' => $vendor->id, 'delivery_warehouse_id' => $warehouse->id,
            'status' => 'ISSUED', 'order_date' => '2026-10-01', 'subtotal' => $qty * 1000, 'tax_total' => 0, 'freight_cost' => 0, 'total' => $qty * 1000,
        ]);
        PurchaseOrderItem::query()->create(['purchase_order_id' => $po->id, 'product_id' => $product->id, 'quantity_ordered' => $qty, 'quantity_received' => 0, 'unit_price' => 1000, 'line_total' => $qty * 1000]);

        return $po;
    }

    private function receive(PurchaseOrder $po, array $headers, float $qty, array $invoice)
    {
        $this->app['auth']->forgetGuards();

        return $this->post("/api/v1/app/purchase-orders/{$po->id}/goods-receipts", array_merge([
            'lines' => [['purchase_order_item_id' => $po->items()->first()->id, 'quantity_accepted' => $qty]],
        ], $invoice), $headers + ['Accept' => 'application/json']);
    }

    private function newInvoice(string $number, array $extra = []): array
    {
        return array_merge([
            'invoice_mode' => 'NEW', 'vendor_invoice_number' => $number, 'vendor_invoice_date' => '2026-10-02',
            'amount' => '10000000', 'terms_of_payment_days' => '30', 'invoice_document' => $this->realFile('invoice.pdf', self::PDF),
        ], $extra);
    }

    public function test_first_receipt_records_the_invoice_with_its_document_and_working_day_due_date(): void
    {
        [, $warehouse, $product, $vendor, $po, $headers] = $this->scenario();

        $gr = $this->receive($po, $headers, 4, $this->newInvoice('  INV-001  '))->assertCreated()->json('data');

        $invoice = VendorInvoiceReference::query()->findOrFail($gr['vendor_invoice_reference_id']);
        $this->assertSame(['INV-001', $vendor->id, $po->id, 'GOODS_RECEIPT', 30], [$invoice->vendor_invoice_number, $invoice->partner_id, $invoice->purchase_order_id, $invoice->origin, $invoice->terms_of_payment_days]);
        $this->assertSame('10000000.0000', $invoice->amount);
        // Fri 2 Oct 2026 + 30 working days = Fri 13 Nov 2026 (weekends skipped).
        $this->assertSame('2026-11-13', $invoice->due_date->toDateString());
        $this->assertSame(['invoice.pdf', 'application/pdf'], [$invoice->attachment_original_name, $invoice->attachment_mime_type]);
        Storage::disk('local')->assertExists($invoice->attachment_path);
        $this->assertSame('PARTIALLY_RECEIVED', $po->fresh()->status);
        $this->assertSame(4.0, (float) WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity_on_hand'));

        $this->app['auth']->forgetGuards();
        $download = $this->get("/api/v1/app/vendor-invoice-references/{$invoice->id}/download", $headers)->assertOk();
        $this->assertSame(self::PDF, $download->streamedContent());
    }

    public function test_partial_receipts_reuse_the_same_invoice_without_duplicating_it_and_keep_history(): void
    {
        [, , , , $po, $headers] = $this->scenario();
        $first = $this->receive($po, $headers, 4, $this->newInvoice('INV-A'))->assertCreated()->json('data');
        $filesAfterFirst = Storage::disk('local')->allFiles();

        $second = $this->receive($po, $headers, 6, ['invoice_mode' => 'EXISTING', 'vendor_invoice_reference_id' => $first['vendor_invoice_reference_id']])->assertCreated()->json('data');

        $this->assertSame($first['vendor_invoice_reference_id'], $second['vendor_invoice_reference_id'], 'Both receipts point at one invoice.');
        $this->assertNotSame($first['gr_number'], $second['gr_number']);
        $this->assertSame(1, VendorInvoiceReference::query()->where('purchase_order_id', $po->id)->count(), 'No duplicate invoice row.');
        $this->assertSame($filesAfterFirst, Storage::disk('local')->allFiles(), 'No duplicate file.');
        $this->assertSame('RECEIVED', $po->fresh()->status);

        $this->app['auth']->forgetGuards();
        $history = $this->getJson("/api/v1/app/purchase-orders/{$po->id}", $headers)->assertOk()->json('data.goods_receipts');
        $this->assertSame([$first['gr_number'], $second['gr_number']], array_column($history, 'gr_number'), 'Every receipt kept, oldest first.');
        $this->assertNotNull($history[0]['received_at']);
        $this->assertSame(['INV-A', 'INV-A'], array_map(fn ($gr) => $gr['vendor_invoice_reference']['vendor_invoice_number'], $history));
        $this->assertSame(['4.0000', '6.0000'], array_map(fn ($gr) => $gr['items'][0]['quantity_accepted'], $history));
        $this->assertTrue($history[1]['vendor_invoice_reference']['has_document']);
    }

    public function test_a_later_receipt_can_record_a_different_invoice(): void
    {
        [, , , , $po, $headers] = $this->scenario();
        $first = $this->receive($po, $headers, 4, $this->newInvoice('INV-A'))->assertCreated()->json('data');
        $second = $this->receive($po, $headers, 3, $this->newInvoice('INV-B', ['amount' => '3000000', 'terms_of_payment_days' => '0']))->assertCreated()->json('data');

        $this->assertNotSame($first['vendor_invoice_reference_id'], $second['vendor_invoice_reference_id']);
        $b = VendorInvoiceReference::query()->findOrFail($second['vendor_invoice_reference_id']);
        $this->assertSame(['INV-B', '2026-10-02'], [$b->vendor_invoice_number, $b->due_date->toDateString()], '0 working days = invoice date.');
        $this->assertSame('PARTIALLY_RECEIVED', $po->fresh()->status);
    }

    public function test_nothing_is_saved_when_the_receipt_or_invoice_is_rejected(): void
    {
        [$tenant, $warehouse, $product, $vendor, $po, $headers] = $this->scenario();

        // Over-receipt: the invoice and its PDF must not survive the failed receipt.
        $this->receive($po, $headers, 11, $this->newInvoice('INV-X'))->assertStatus(422);
        // Invoice document must be a PDF (checked by name and by content).
        $this->receive($po, $headers, 2, $this->newInvoice('INV-X', ['invoice_document' => $this->realFile('invoice.jpg', "\xFF\xD8\xFF\xE0jpeg")]))->assertStatus(422)->assertJsonValidationErrors('invoice_document');
        $this->receive($po, $headers, 2, $this->newInvoice('INV-X', ['invoice_document' => $this->realFile('invoice.pdf', 'not a pdf')]))->assertStatus(422);
        // Amount and terms are validated server-side.
        $this->receive($po, $headers, 2, $this->newInvoice('INV-X', ['amount' => '12a']))->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->receive($po, $headers, 2, $this->newInvoice('INV-X', ['amount' => '0']))->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->receive($po, $headers, 2, $this->newInvoice('INV-X', ['terms_of_payment_days' => '1.5']))->assertStatus(422)->assertJsonValidationErrors('terms_of_payment_days');
        $this->receive($po, $headers, 2, $this->newInvoice('INV-X', ['terms_of_payment_days' => '-1']))->assertStatus(422)->assertJsonValidationErrors('terms_of_payment_days');

        $this->assertSame(0, GoodsReceipt::query()->where('purchase_order_id', $po->id)->count());
        $this->assertSame(0, VendorInvoiceReference::query()->where('tenant_id', $tenant->id)->count());
        $this->assertSame([], Storage::disk('local')->allFiles(), 'No orphan invoice file.');
        $this->assertSame('ISSUED', $po->fresh()->status);
        $this->assertNull(WarehouseStock::query()->where('warehouse_id', $warehouse->id)->where('product_id', $product->id)->value('quantity_on_hand'));

        // Duplicate vendor + invoice number (case-insensitive) on a NEW invoice is rejected.
        $this->receive($po, $headers, 2, $this->newInvoice('INV-D'))->assertCreated();
        $other = $this->issuedPo($tenant, $vendor, $warehouse, $product);
        $this->receive($other, $headers, 2, $this->newInvoice('inv-d'))->assertStatus(422)->assertJsonFragment(['message' => 'Invoice inv-d from this vendor is already recorded. To receive more goods against it, use the same invoice as the previous Goods Receipt.']);
        $this->assertSame(0, GoodsReceipt::query()->where('purchase_order_id', $other->id)->count());

        // The database enforces it too (two concurrent receipts cannot both create it).
        $this->expectException(UniqueConstraintViolationException::class);
        VendorInvoiceReference::query()->create(['tenant_id' => $tenant->id, 'partner_id' => $vendor->id, 'vendor_invoice_number' => 'Inv-D', 'vendor_invoice_date' => '2026-10-02', 'amount' => 1, 'origin' => 'GOODS_RECEIPT']);
    }

    public function test_reusing_an_invoice_is_limited_to_earlier_receipts_of_the_same_po(): void
    {
        [$tenant, $warehouse, $product, $vendor, $po, $headers] = $this->scenario();
        $first = $this->receive($po, $headers, 2, $this->newInvoice('INV-A'))->assertCreated()->json('data');
        $other = $this->issuedPo($tenant, $vendor, $warehouse, $product);

        $this->receive($other, $headers, 2, ['invoice_mode' => 'EXISTING', 'vendor_invoice_reference_id' => $first['vendor_invoice_reference_id']])
            ->assertStatus(422)->assertJsonFragment(['message' => 'The selected invoice was not received against an earlier Goods Receipt of this Purchase Order.']);
        $this->receive($po, $headers, 2, ['invoice_mode' => 'EXISTING'])->assertStatus(422)->assertJsonValidationErrors('vendor_invoice_reference_id');
    }

    public function test_invoice_documents_are_tenant_and_scope_protected(): void
    {
        [$tenant, , , , $po, $headers] = $this->scenario();
        $invoiceId = $this->receive($po, $headers, 2, $this->newInvoice('INV-A'))->assertCreated()->json('data.vendor_invoice_reference_id');

        $other = $this->makeTenant(['code' => 'GRX-'.Str::random(4)]);
        $this->grantModule($other, 'PROCUREMENT');
        [, $foreign] = $this->makeTenantUser($other, self::PERMISSIONS);
        $this->app['auth']->forgetGuards();
        $this->get("/api/v1/app/vendor-invoice-references/{$invoiceId}/download", $this->authHeaders($foreign))->assertNotFound();

        $elsewhere = $this->makeWarehouse($tenant, $this->makeBranch($tenant));
        [, $scoped] = $this->makeTenantUser($tenant, self::PERMISSIONS, ['WAREHOUSE' => $elsewhere->id]);
        $this->app['auth']->forgetGuards();
        $this->get("/api/v1/app/vendor-invoice-references/{$invoiceId}/download", $this->authHeaders($scoped))->assertForbidden();
    }

    public function test_working_day_calculation(): void
    {
        $days = app(WorkingDayService::class);
        $friday = CarbonImmutable::parse('2026-10-02');
        $this->assertSame('2026-10-05', $days->addBusinessDays($friday, 1)->toDateString(), 'Friday + 1 = Monday, not Saturday.');
        $this->assertSame('2026-10-09', $days->addBusinessDays($friday, 5)->toDateString());
        $this->assertSame('2026-10-16', $days->addBusinessDays($friday, 10)->toDateString(), 'Multi-week.');
        $this->assertSame('2026-10-05', $days->addBusinessDays(CarbonImmutable::parse('2026-10-03'), 1)->toDateString(), 'Saturday + 1 = Monday.');
        $this->assertSame('2026-10-02', $days->addBusinessDays($friday, 0)->toDateString());
    }
}
