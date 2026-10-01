<?php

namespace Tests\Feature;

use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseOrderItem;
use App\Domain\Procurement\Models\VendorInvoiceReference;
use App\Domain\Procurement\Support\VendorInvoiceStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Vendor Invoice References: GR-linked tracking list (one row per Goods Receipt), backend-derived
 * NEW / DUE_SOON / LATE status from a working-day due date, no standalone creation.
 */
class VendorInvoiceReferenceListTest extends TestCase
{
    private const POSTER = ['purchase_order.view', 'goods_receipt.view', 'goods_receipt.post', 'vendor_invoice.view'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00')); // Monday
    }

    private function scenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'VIL-'.Str::random(4)]);
        foreach (['INVENTORY', 'PROCUREMENT', 'PARTNER'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $warehouse = $this->makeWarehouse($tenant, $this->makeBranch($tenant));
        $product = $this->makeProduct($tenant);
        $vendor = $this->makePartner($tenant, ['name' => 'PT Vendor Dua']);
        [, $token] = $this->makeTenantUser($tenant, self::POSTER);

        return [$tenant, $warehouse, $product, $vendor, $this->authHeaders($token)];
    }

    private function po($tenant, $vendor, $warehouse, $product, string $number): PurchaseOrder
    {
        $po = PurchaseOrder::query()->create([
            'tenant_id' => $tenant->id, 'po_number' => $number, 'partner_id' => $vendor->id, 'delivery_warehouse_id' => $warehouse->id,
            'status' => 'ISSUED', 'order_date' => '2026-10-01', 'subtotal' => 10000, 'tax_total' => 0, 'freight_cost' => 0, 'total' => 10000,
        ]);
        PurchaseOrderItem::query()->create(['purchase_order_id' => $po->id, 'product_id' => $product->id, 'quantity_ordered' => 10, 'quantity_received' => 0, 'unit_price' => 1000, 'line_total' => 10000]);

        return $po;
    }

    private function receive(PurchaseOrder $po, array $headers, float $qty, array $invoice): array
    {
        $this->app['auth']->forgetGuards();

        return $this->postJson("/api/v1/app/purchase-orders/{$po->id}/goods-receipts", array_merge([
            'lines' => [['purchase_order_item_id' => $po->items()->first()->id, 'quantity_accepted' => $qty]],
        ], $invoice), $headers)->assertCreated()->json('data');
    }

    private function list(array $headers, string $query = ''): array
    {
        $this->app['auth']->forgetGuards();

        return $this->getJson('/api/v1/app/vendor-invoice-references?'.$query, $headers)->assertOk()->json();
    }

    public function test_one_row_per_goods_receipt_with_shared_invoice_data(): void
    {
        [$tenant, $warehouse, $product, $vendor, $headers] = $this->scenario();
        $po = $this->po($tenant, $vendor, $warehouse, $product, 'PO-001');
        $gr1 = $this->receive($po, $headers, 4, ['invoice_mode' => 'NEW', 'vendor_invoice_number' => 'INV-001', 'vendor_invoice_date' => '2026-10-01', 'amount' => '10000000', 'terms_of_payment_days' => '30']);
        $gr2 = $this->receive($po, $headers, 6, ['invoice_mode' => 'EXISTING', 'vendor_invoice_reference_id' => $gr1['vendor_invoice_reference_id']]);
        // A legacy, manually recorded invoice not linked to any receipt is kept but not listed.
        VendorInvoiceReference::query()->create(['tenant_id' => $tenant->id, 'partner_id' => $vendor->id, 'vendor_invoice_number' => 'LEGACY-1', 'vendor_invoice_date' => '2026-09-01', 'amount' => 5, 'origin' => 'MANUAL']);

        $rows = $this->list($headers)['data'];
        $this->assertSame([$gr2['gr_number'], $gr1['gr_number']], array_column($rows, 'gr_number'), 'Newest receipt first, one row each.');
        foreach ($rows as $row) {
            $this->assertSame('PO-001', $row['purchase_order']['po_number']);
            $this->assertSame(
                [$gr1['vendor_invoice_reference_id'], 'INV-001', 'PT Vendor Dua', '10000000.0000', 30, '2026-10-01', '2026-11-12', 'NEW'],
                [$row['invoice']['id'], $row['invoice']['vendor_invoice_number'], $row['invoice']['partner']['name'], $row['invoice']['amount'], $row['invoice']['terms_of_payment_days'], $row['invoice']['vendor_invoice_date'], $row['invoice']['due_date'], $row['invoice']['status']],
            );
        }
        $this->assertSame(1, VendorInvoiceReference::query()->where('vendor_invoice_number', 'LEGACY-1')->count(), 'Legacy row untouched.');
        $this->assertCount(0, $this->list($headers, 'search=LEGACY')['data']);
        $this->assertCount(2, $this->list($headers, 'search=inv-001')['data']);
        $this->assertCount(2, $this->list($headers, 'search=po-001')['data']);
        $this->assertCount(1, $this->list($headers, 'search='.urlencode($gr1['gr_number']))['data']);
    }

    public function test_status_is_derived_from_the_due_date_and_filterable(): void
    {
        config(['procurement.invoice_due_soon_days' => 7]);
        [$tenant, $warehouse, $product, $vendor, $headers] = $this->scenario();
        // Today: Mon 5 Oct 2026.
        $cases = [
            'LATE' => ['2026-09-01', '10'],     // due Tue 15 Sep → past
            'DUE_SOON' => ['2026-10-02', '5'],  // due Fri 9 Oct → within 7 days
            'NEW' => ['2026-10-02', '30'],      // due Fri 13 Nov → later
            'EDGE' => ['2026-10-05', '0'],      // due today → still DUE_SOON, not LATE
        ];
        foreach ($cases as $label => [$date, $terms]) {
            $po = $this->po($tenant, $vendor, $warehouse, $product, "PO-{$label}");
            $this->receive($po, $headers, 1, ['invoice_mode' => 'NEW', 'vendor_invoice_number' => "INV-{$label}", 'vendor_invoice_date' => $date, 'amount' => '100', 'terms_of_payment_days' => $terms]);
        }

        $byNumber = collect($this->list($headers)['data'])->mapWithKeys(fn ($r) => [$r['invoice']['vendor_invoice_number'] => [$r['invoice']['due_date'], $r['invoice']['status']]]);
        $this->assertSame(['2026-09-15', 'LATE'], $byNumber['INV-LATE']);
        $this->assertSame(['2026-10-09', 'DUE_SOON'], $byNumber['INV-DUE_SOON']);
        $this->assertSame(['2026-11-13', 'NEW'], $byNumber['INV-NEW']);
        $this->assertSame(['2026-10-05', 'DUE_SOON'], $byNumber['INV-EDGE']);

        $this->assertSame(['INV-LATE'], array_map(fn ($r) => $r['invoice']['vendor_invoice_number'], $this->list($headers, 'status=LATE')['data']));
        $this->assertEqualsCanonicalizing(['INV-DUE_SOON', 'INV-EDGE'], array_map(fn ($r) => $r['invoice']['vendor_invoice_number'], $this->list($headers, 'status=DUE_SOON')['data']));
        $this->assertSame(['INV-NEW'], array_map(fn ($r) => $r['invoice']['vendor_invoice_number'], $this->list($headers, 'status=NEW')['data']));
        $this->assertCount(0, $this->list($headers, 'status=PAID')['data']);

        // Time passes: the stored due date is unchanged, the status moves on by itself.
        $this->travelTo(CarbonImmutable::parse('2026-10-10 09:00:00'));
        $this->assertSame('LATE', collect($this->list($headers)['data'])->firstWhere('invoice.vendor_invoice_number', 'INV-DUE_SOON')['invoice']['status']);
        $this->assertSame('NEW', VendorInvoiceStatus::resolve(null, false, VendorInvoiceStatus::today(null)), 'Legacy invoice without due date.');
    }

    public function test_access_is_permission_tenant_and_scope_bound_and_creation_is_gone(): void
    {
        [$tenant, $warehouse, $product, $vendor, $headers] = $this->scenario();
        $po = $this->po($tenant, $vendor, $warehouse, $product, 'PO-SEC');
        $this->receive($po, $headers, 1, ['invoice_mode' => 'NEW', 'vendor_invoice_number' => 'INV-SEC', 'vendor_invoice_date' => '2026-10-01', 'amount' => '100', 'terms_of_payment_days' => '1']);

        [, $noPerm] = $this->makeTenantUser($tenant, ['goods_receipt.view']);
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/app/vendor-invoice-references', $this->authHeaders($noPerm))->assertForbidden();

        $elsewhere = $this->makeWarehouse($tenant, $this->makeBranch($tenant));
        [, $scoped] = $this->makeTenantUser($tenant, ['vendor_invoice.view'], ['WAREHOUSE' => $elsewhere->id]);
        $this->assertCount(0, $this->list($this->authHeaders($scoped))['data'], 'Warehouse data scope.');

        $other = $this->makeTenant(['code' => 'VIX-'.Str::random(4)]);
        $this->grantModule($other, 'PROCUREMENT');
        [, $foreign] = $this->makeTenantUser($other, ['vendor_invoice.view']);
        $this->assertCount(0, $this->list($this->authHeaders($foreign))['data'], 'Tenant isolation.');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/app/vendor-invoice-references', ['partner_id' => $vendor->id, 'vendor_invoice_number' => 'X', 'vendor_invoice_date' => '2026-10-01', 'amount' => 1], $headers)
            ->assertStatus(405);
    }

    public function test_existing_goods_receipt_viewers_get_the_new_permission(): void
    {
        $tenant = $this->makeTenant(['code' => 'VIP-'.Str::random(4)]);
        [$user] = $this->makeTenantUser($tenant, ['goods_receipt.view']);
        $roleId = DB::table('role_assignments')->where('user_id', $user->id)->value('role_id');
        $permissionId = DB::table('permissions')->where('name', 'vendor_invoice.view')->value('id');
        DB::table('role_permissions')->where('permission_id', $permissionId)->delete();

        (require database_path('migrations/2026_10_02_000002_add_vendor_invoice_view_permission.php'))->up();

        $this->assertTrue(DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->exists());
    }
}
