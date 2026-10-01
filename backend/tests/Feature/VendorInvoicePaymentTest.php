<?php

namespace Tests\Feature;

use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Domain\Procurement\Models\VendorInvoicePayment;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Payment of a vendor invoice (full settlement, with proof): belongs to the invoice — a shared
 * invoice is paid once and every receipt row shows PAID; double payment is impossible.
 */
class VendorInvoicePaymentTest extends TestCase
{
    private const PDF = "%PDF-1.4\n1 0 obj<< /Type /Catalog >>endobj\ntrailer<< /Root 1 0 R >>\n%%EOF\n";

    private const PNG = "\x89PNG\r\n\x1a\n\0\0\0\rIHDR\0\0\0\x01\0\0\0\x01\x08\x06\0\0\0\x1f\x15\xc4\x89\0\0\0\rIDATx\x9cc\xf8\xff\xff?\0\x05\xfe\x02\xfe\xa7\x35\x81\x84\0\0\0\0IEND\xaeB`\x82";

    private const JPG = "\xFF\xD8\xFF\xE0\0\x10JFIF\0\x01\x01\0\0\x01\0\x01\0\0\xFF\xD9";

    private const FINANCE = ['purchase_order.view', 'goods_receipt.view', 'goods_receipt.post', 'vendor_invoice.view', 'vendor_invoice.pay'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-20 10:00:00'));
    }

    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'vip');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function scenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'VIP-'.Str::random(4)]);
        foreach (['INVENTORY', 'PROCUREMENT', 'PARTNER'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $warehouse = $this->makeWarehouse($tenant, $this->makeBranch($tenant));
        $product = $this->makeProduct($tenant);
        $vendor = $this->makePartner($tenant, ['name' => 'PT Vendor Tiga']);
        $po = PurchaseOrder::query()->create([
            'tenant_id' => $tenant->id, 'po_number' => 'PO-PAY-'.Str::random(4), 'partner_id' => $vendor->id, 'delivery_warehouse_id' => $warehouse->id,
            'status' => 'ISSUED', 'order_date' => '2026-10-01', 'subtotal' => 10000, 'tax_total' => 0, 'freight_cost' => 0, 'total' => 10000,
        ]);
        PurchaseOrderItem::query()->create(['purchase_order_id' => $po->id, 'product_id' => $product->id, 'quantity_ordered' => 10, 'quantity_received' => 0, 'unit_price' => 1000, 'line_total' => 10000]);
        [, $token] = $this->makeTenantUser($tenant, self::FINANCE);

        return [$tenant, $warehouse, $po, $this->authHeaders($token)];
    }

    private function receive(PurchaseOrder $po, array $headers, float $qty, array $invoice): array
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson("/api/v1/app/purchase-orders/{$po->id}/goods-receipts", array_merge([
            'lines' => [['purchase_order_item_id' => $po->items()->first()->id, 'quantity_accepted' => $qty]],
        ], $invoice), $headers)->assertCreated()->json('data');
    }

    private function newInvoice(string $number, string $amount = '12500000.50'): array
    {
        return ['invoice_mode' => 'NEW', 'vendor_invoice_number' => $number, 'vendor_invoice_date' => '2026-10-01', 'amount' => $amount, 'terms_of_payment_days' => '30'];
    }

    private function pay(string $invoiceId, array $headers, array $data)
    {
        $this->app['auth']->forgetGuards();

        return $this->post("/api/v1/app/vendor-invoice-references/{$invoiceId}/payments", $data, $headers + ['Accept' => 'application/json']);
    }

    private function rows(array $headers, string $query = ''): array
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/v1/app/vendor-invoice-references?'.$query, $headers)->assertOk()->json('data');
    }

    public function test_paying_an_invoice_records_payment_and_proof_and_marks_it_paid(): void
    {
        [, , $po, $headers] = $this->scenario();
        $invoiceId = $this->receive($po, $headers, 2, $this->newInvoice('INV-P1'))['vendor_invoice_reference_id'];
        $this->assertSame('NEW', $this->rows($headers)[0]['invoice']['status']);

        $this->pay($invoiceId, $headers, ['payment_date' => '2026-10-19', 'amount' => '12500000.50', 'payment_proof' => $this->realFile('transfer.pdf', self::PDF)])
            ->assertCreated()->assertJsonPath('data.amount', '12500000.5000');

        $row = $this->rows($headers)[0]['invoice'];
        $this->assertSame(['PAID', '2026-11-12', '2026-10-19', 'transfer.pdf'], [$row['status'], $row['due_date'], $row['payment']['payment_date'], $row['payment']['proof_original_name']]);
        $this->assertCount(1, $this->rows($headers, 'status=PAID'));
        $this->assertCount(0, $this->rows($headers, 'status=NEW'));

        $this->app['auth']->forgetGuards();
        $proof = $this->get("/api/v1/app/vendor-invoice-references/{$invoiceId}/payment-proof", $headers)->assertOk();
        $this->assertSame(self::PDF, $proof->streamedContent());
        $this->assertStringContainsString('application/pdf', $proof->headers->get('Content-Type'));
    }

    public function test_an_invoice_cannot_be_paid_twice(): void
    {
        [, , $po, $headers] = $this->scenario();
        $invoiceId = $this->receive($po, $headers, 2, $this->newInvoice('INV-P2', '100'))['vendor_invoice_reference_id'];
        $this->pay($invoiceId, $headers, ['payment_date' => '2026-10-19', 'amount' => '100', 'payment_proof' => $this->realFile('a.png', self::PNG)])->assertCreated();
        $files = Storage::disk('local')->allFiles();

        $this->pay($invoiceId, $headers, ['payment_date' => '2026-10-20', 'amount' => '100', 'payment_proof' => $this->realFile('b.png', self::PNG)])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Invoice INV-P2 is already paid.']);
        $this->assertSame(1, VendorInvoicePayment::query()->withoutGlobalScopes()->where('vendor_invoice_reference_id', $invoiceId)->count());
        $this->assertSame($files, Storage::disk('local')->allFiles(), 'The rejected second proof is not kept.');
    }

    public function test_a_shared_invoice_is_paid_once_and_every_receipt_row_shows_paid(): void
    {
        [, , $po, $headers] = $this->scenario();
        $gr1 = $this->receive($po, $headers, 4, $this->newInvoice('INV-SHARED', '500'));
        $this->receive($po, $headers, 6, ['invoice_mode' => 'EXISTING', 'vendor_invoice_reference_id' => $gr1['vendor_invoice_reference_id']]);
        $this->assertSame(['NEW', 'NEW'], array_map(fn ($r) => $r['invoice']['status'], $this->rows($headers)));

        $this->pay($gr1['vendor_invoice_reference_id'], $headers, ['payment_date' => '2026-10-20', 'amount' => '500.00', 'payment_proof' => $this->realFile('proof.jpg', self::JPG)])->assertCreated();

        $rows = $this->rows($headers);
        $this->assertCount(2, $rows);
        $this->assertSame(['PAID', 'PAID'], array_map(fn ($r) => $r['invoice']['status'], $rows));
        $this->assertSame(['2026-10-20', '2026-10-20'], array_map(fn ($r) => $r['invoice']['payment']['payment_date'], $rows));
        $this->assertSame(1, VendorInvoicePayment::query()->withoutGlobalScopes()->count(), 'One payment for the shared invoice.');
    }

    public function test_payment_is_validated_on_the_server_and_rejections_save_nothing(): void
    {
        [, , $po, $headers] = $this->scenario();
        $invoiceId = $this->receive($po, $headers, 2, $this->newInvoice('INV-V', '1000'))['vendor_invoice_reference_id'];
        $proof = fn () => $this->realFile('proof.png', self::PNG);

        $this->pay($invoiceId, $headers, ['payment_date' => '2026-10-20', 'amount' => '999.99', 'payment_proof' => $proof()])
            ->assertStatus(422)->assertJsonFragment(['message' => 'The payment amount must equal the invoice amount (1000.00); partial payments are not supported.']);
        $this->pay($invoiceId, $headers, ['payment_date' => '2026-10-20', 'amount' => '0', 'payment_proof' => $proof()])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->pay($invoiceId, $headers, ['payment_date' => '2026-10-20', 'amount' => '1,000', 'payment_proof' => $proof()])->assertStatus(422)->assertJsonValidationErrors('amount');
        $this->pay($invoiceId, $headers, ['payment_date' => 'yesterday-ish', 'amount' => '1000', 'payment_proof' => $proof()])->assertStatus(422)->assertJsonValidationErrors('payment_date');
        $this->pay($invoiceId, $headers, ['payment_date' => '2026-10-21', 'amount' => '1000', 'payment_proof' => $proof()])
            ->assertStatus(422)->assertJsonFragment(['message' => 'The payment date cannot be in the future.']);
        $this->pay($invoiceId, $headers, ['payment_date' => '2026-10-20', 'amount' => '1000'])->assertStatus(422)->assertJsonValidationErrors('payment_proof');
        $this->pay($invoiceId, $headers, ['payment_date' => '2026-10-20', 'amount' => '1000', 'payment_proof' => $this->realFile('setup.exe', "MZ\x90\0binary")])->assertStatus(422)->assertJsonValidationErrors('payment_proof');
        $this->pay($invoiceId, $headers, ['payment_date' => '2026-10-20', 'amount' => '1000', 'payment_proof' => $this->realFile('fake.png', 'just text')])->assertStatus(422)->assertJsonValidationErrors('payment_proof');

        $this->assertSame(0, VendorInvoicePayment::query()->withoutGlobalScopes()->count());
        $this->assertSame([], Storage::disk('local')->files('vendor-invoice-payments', true), 'No proof stored for a rejected payment.');
        $this->assertSame('NEW', $this->rows($headers)[0]['invoice']['status']);
    }

    public function test_payment_requires_permission_tenant_and_scope(): void
    {
        [$tenant, , $po, $headers] = $this->scenario();
        $invoiceId = $this->receive($po, $headers, 2, $this->newInvoice('INV-S', '10'))['vendor_invoice_reference_id'];
        $body = fn () => ['payment_date' => '2026-10-20', 'amount' => '10', 'payment_proof' => $this->realFile('p.png', self::PNG)];

        [, $viewer] = $this->makeTenantUser($tenant, ['vendor_invoice.view']);
        $this->pay($invoiceId, $this->authHeaders($viewer), $body())->assertForbidden();

        $other = $this->makeTenant(['code' => 'VPX-'.Str::random(4)]);
        $this->grantModule($other, 'PROCUREMENT');
        [, $foreign] = $this->makeTenantUser($other, self::FINANCE);
        $this->pay($invoiceId, $this->authHeaders($foreign), $body())->assertNotFound();

        $elsewhere = $this->makeWarehouse($tenant, $this->makeBranch($tenant));
        [, $scoped] = $this->makeTenantUser($tenant, self::FINANCE, ['WAREHOUSE' => $elsewhere->id]);
        $this->pay($invoiceId, $this->authHeaders($scoped), $body())->assertForbidden();

        $this->pay($invoiceId, $headers, $body())->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->get("/api/v1/app/vendor-invoice-references/{$invoiceId}/payment-proof", $this->authHeaders($foreign))->assertNotFound();
        $this->assertSame(1, VendorInvoicePayment::query()->withoutGlobalScopes()->count());
    }

    public function test_invoice_managers_get_the_pay_permission_and_the_legacy_permission_is_retired(): void
    {
        $tenant = $this->makeTenant(['code' => 'VPM-'.Str::random(4)]);
        $legacy = (string) Str::uuid();
        DB::table('permissions')->insert(['id' => $legacy, 'name' => 'goods_receipt.create', 'group' => 'goods_receipt', 'scope' => 'tenant', 'created_at' => now(), 'updated_at' => now()]);
        [$user] = $this->makeTenantUser($tenant, ['goods_receipt.create']);
        $roleId = DB::table('role_assignments')->where('user_id', $user->id)->value('role_id');
        $this->assertTrue(DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $legacy)->exists());

        $migration = require database_path('migrations/2026_10_02_000003_create_vendor_invoice_payments.php');
        (fn () => $this->grantPay())->call($migration);

        $pay = DB::table('permissions')->where('name', 'vendor_invoice.pay')->value('id');
        $this->assertTrue(DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $pay)->exists());
        $this->assertFalse(DB::table('permissions')->where('name', 'goods_receipt.create')->exists());
    }
}
