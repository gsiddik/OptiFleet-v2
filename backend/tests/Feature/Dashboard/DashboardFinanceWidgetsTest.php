<?php

namespace Tests\Feature\Dashboard;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Service Cost (FN-01/02/03) definition and reconciliation, payables aging (FN-04) and refunds (FN-06):
 * recognition dates, statuses, rounding, currencies, payments/estimates never counted, permissions.
 */
class DashboardFinanceWidgetsTest extends TestCase
{
    use DashboardTestHelpers;

    private array $s;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function id(): string
    {
        return (string) Str::uuid();
    }

    private function part(string $tenantId, string $workOrderId, string $issued, string $totalCost, string $consumed, string $returned = '0'): void
    {
        DB::table('work_order_planned_parts')->insert(['id' => $this->id(), 'tenant_id' => $tenantId, 'work_order_id' => $workOrderId, 'description' => 'Part',
            'issued_quantity' => $issued, 'consumed_quantity' => $consumed, 'returned_quantity' => $returned, 'total_cost' => $totalCost,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function memo(string $tenantId, string $workOrderId, string $partnerId, string $cost, string $status = 'COMPLETED'): string
    {
        $id = $this->id();
        DB::table('work_order_external_services')->insert(['id' => $id, 'tenant_id' => $tenantId, 'work_order_id' => $workOrderId, 'partner_id' => $partnerId,
            'description' => 'Machining', 'cost' => $cost, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function serviceInvoice(string $tenantId, string $workOrderId, string $memoId, string $partnerId, string $number, string $date, string $total, array $extra = []): string
    {
        $id = $this->id();
        DB::table('workshop_invoices')->insert(array_merge(['id' => $id, 'tenant_id' => $tenantId, 'work_order_id' => $workOrderId,
            'work_order_external_service_id' => $memoId, 'partner_id' => $partnerId, 'external_invoice_number' => $number,
            'external_invoice_number_normalized' => strtoupper($number), 'invoice_date' => $date, 'subtotal' => $total, 'tax_total' => 0,
            'total_amount' => $total, 'currency' => 'IDR', 'status' => 'RECORDED', 'created_at' => now(), 'updated_at' => now()], $extra));

        return $id;
    }

    /** Jakarta tenant, two branches/vehicles, one workshop, a full cost scenario. */
    private function scenario(): array
    {
        Carbon::setTestNow('2026-10-07 03:00:00'); // 10:00 in Jakarta
        $tenant = $this->makeTenant(['timezone' => 'Asia/Jakarta']);
        $this->grantModules($tenant, ['VEHICLE', 'WORK_ORDER', 'PROCUREMENT', 'INVENTORY', 'PARTNER']);
        $jkt = $this->makeBranch($tenant, ['code' => 'JKT', 'name' => 'Jakarta']);
        $bdg = $this->makeBranch($tenant, ['code' => 'BDG', 'name' => 'Bandung']);
        $workshop = $this->makeWorkshop($tenant, $jkt);
        $category = $this->makeVehicleCategory();
        $a = $this->makeVehicle($tenant, $jkt, $category, ['registration_number' => 'B 1 AAA']);
        $b = $this->makeVehicle($tenant, $bdg, $category, ['registration_number' => 'D 2 BBB']);
        $vendor = $this->makePartner($tenant);

        // WO1 completed 2026-08-31 18:00 UTC = 2026-09-01 01:00 Jakarta → recognized in September.
        $wo1 = $this->makeWorkOrder($tenant, $jkt, $workshop, $a, ['status' => 'COMPLETED', 'estimated_total_cost' => 999999]);
        DB::table('work_orders')->where('id', $wo1->id)->update(['completed_at' => '2026-08-31 18:00:00']);
        // 3 issued for 100.00 (unit 33.3333), 2 consumed, 1 returned → 66.67; a second line 1 × 10.005 → 10.01.
        $this->part($tenant->id, $wo1->id, '3', '100.0000', '2', '1');
        $this->part($tenant->id, $wo1->id, '1', '10.0050', '1');
        // Service invoice 1,100 (incl. tax) paid in full: the payment is not a second cost; the memo estimate never counts.
        $memo1 = $this->memo($tenant->id, $wo1->id, $vendor->id, '900.00', 'PAID');
        $inv = $this->serviceInvoice($tenant->id, $wo1->id, $memo1, $vendor->id, 'SI-001', '2026-09-10', '1100.00', ['subtotal' => '1000.00', 'tax_total' => '100.00']);
        DB::table('work_order_external_services')->where('id', $memo1)->update(['workshop_invoice_id' => $inv]);
        DB::table('workshop_invoice_payments')->insert(['id' => $this->id(), 'tenant_id' => $tenant->id, 'workshop_invoice_id' => $inv,
            'payment_date' => '2026-09-20', 'paid_amount' => '1100.00', 'evidence_url' => 'x', 'uploaded_by' => $this->makeTenantUser($tenant)[0]->id, 'uploaded_at' => now()]);
        // Cancelled and foreign-currency invoices are left out.
        $memo2 = $this->memo($tenant->id, $wo1->id, $vendor->id, '500.00', 'CANCELLED');
        $this->serviceInvoice($tenant->id, $wo1->id, $memo2, $vendor->id, 'SI-CXL', '2026-09-11', '500.00', ['status' => 'CANCELLED']);
        $memo3 = $this->memo($tenant->id, $wo1->id, $vendor->id, '200.00', 'BILLED');
        $this->serviceInvoice($tenant->id, $wo1->id, $memo3, $vendor->id, 'SI-USD', '2026-09-12', '200.00', ['currency' => 'USD', 'due_date' => '2026-09-30']);
        // Completed memo without an invoice: shown as pending estimate, never in the bars.
        $this->memo($tenant->id, $wo1->id, $vendor->id, '300.00');

        // WO2 cancelled with consumed parts → excluded; WO3 open with consumed parts → pending only.
        $wo2 = $this->makeWorkOrder($tenant, $jkt, $workshop, $a, ['status' => 'CANCELLED']);
        $this->part($tenant->id, $wo2->id, '1', '40.0000', '1');
        $wo3 = $this->makeWorkOrder($tenant, $jkt, $workshop, $a, ['status' => 'IN_PROGRESS']);
        $this->part($tenant->id, $wo3->id, '1', '50.0000', '1');

        // External WO for the Bandung vehicle, billed 2026-07-15 for 2,000 (still unpaid, free-text term).
        $wo4 = $this->makeWorkOrder($tenant, $bdg, $workshop, $b, ['status' => 'EXTERNAL']);
        DB::table('work_order_external_invoices')->insert(['id' => $this->id(), 'tenant_id' => $tenant->id, 'branch_id' => $bdg->id, 'work_order_id' => $wo4->id,
            'status' => 'BILLED', 'vendor_invoice_date' => '2026-07-15', 'vendor_invoice_amount' => '2000.0000', 'payment_term' => '30 hari',
            'wal_number' => 'WAL-1', 'created_at' => now(), 'updated_at' => now()]);
        // Older than the 12-month window: never counted.
        $wo5 = $this->makeWorkOrder($tenant, $jkt, $workshop, $a, ['status' => 'CLOSED']);
        DB::table('work_orders')->where('id', $wo5->id)->update(['completed_at' => '2025-09-15 00:00:00']);
        $this->part($tenant->id, $wo5->id, '1', '70.0000', '1');

        return compact('tenant', 'jkt', 'bdg', 'workshop', 'a', 'b', 'vendor');
    }

    public function test_service_cost_definition_and_reconciliation_across_fn01_fn02_fn03(): void
    {
        $s = $this->scenario();
        [, $token] = $this->makeTenantUser($s['tenant'], ['dashboard.finance.view', 'work_order.view']);

        $fn01 = $this->widget($token, 'FN-01')->assertOk()->json('data');
        $this->assertSame('period', $fn01['kind']);
        $this->assertSame('IDR', $fn01['currency']);
        $this->assertCount(13, $fn01['data']['months']);
        $months = collect($fn01['data']['months'])->keyBy('month');
        $this->assertTrue($months['2026-10']['is_current']);
        $this->assertSame('2025-10', $fn01['data']['months'][0]['month']);
        $this->assertSame(['76.68', '1100.00', '0.00', '1176.68'], [$months['2026-09']['PARTS'], $months['2026-09']['EXTERNAL_SERVICE'], $months['2026-09']['EXTERNAL_WO'], $months['2026-09']['total']]);
        $this->assertSame('0.00', $months['2026-08']['total'], 'August stays empty: the WO completed on 1 Sep local time.');
        $this->assertSame('2000.00', $months['2026-07']['EXTERNAL_WO']);
        $this->assertSame('3176.68', $fn01['data']['totals']['total']);
        $this->assertSame(1, $fn01['data']['pending']['parts_on_open_work_orders']['work_orders']);
        $this->assertSame(['count' => 1, 'estimated_amount' => '300.00'], $fn01['data']['pending']['uninvoiced_memos']);
        $this->assertSame('dashboard.limitations.foreignCurrencyExcluded', $fn01['limitations'][0]['code']);
        $this->assertSame('USD', $fn01['limitations'][0]['params']['currency']);

        // FN-02 and FN-03 use the same transactions: same total.
        $fn02 = $this->widget($token, 'FN-02')->json('data.data');
        $this->assertSame(['D 2 BBB', 'B 1 AAA'], array_column($fn02['vehicles'], 'registration_number'));
        $this->assertSame('3176.68', $fn02['totals']['total']);
        $fn03 = $this->widget($token, 'FN-03')->json('data.data');
        $this->assertSame(['Bandung', 'Jakarta'], array_column($fn03['branches'], 'branch_name'));
        $this->assertSame('3176.68', $this->sum(array_column($fn03['branches'], 'total')));

        // Drill-down month → vehicles → transactions reconciles with the bar.
        $vehicles = $this->details($token, 'FN-01', ['month' => '2026-09'])->assertOk()->json('data.data');
        $this->assertCount(1, $vehicles);
        $this->assertSame('1176.68', $vehicles[0]['total']);
        $tx = $this->details($token, 'FN-01', ['month' => '2026-09', 'vehicle_id' => $s['a']->id])->json('data.data');
        $this->assertSame(['EXTERNAL_SERVICE', 'PARTS'], array_column($tx, 'source'));
        $this->assertSame('1176.68', $this->sum(array_column($tx, 'amount')));
        $this->assertSame('2026-09-01', collect($tx)->firstWhere('source', 'PARTS')['recognized_on']);
        $branchRows = $this->details($token, 'FN-03', ['attributed_branch_id' => $s['bdg']->id])->json('data.data');
        $this->assertSame('2000.00', $branchRows[0]['total']);

        // 3 full months + the running month = Jul..Oct: the same transactions, the out-of-window WO (Sep 2025) never counted.
        $short = $this->widget($token, 'FN-01', ['months' => 3])->json('data');
        $this->assertSame(['2026-07', '2026-08', '2026-09', '2026-10'], array_column($short['data']['months'], 'month'));
        $this->assertSame('3176.68', $short['data']['totals']['total']);
        $this->assertSame(3, $short['filters']['months']);
    }

    public function test_service_cost_requires_finance_permission_and_follows_workshop_scope(): void
    {
        $s = $this->scenario();
        [, $viewer] = $this->makeTenantUser($s['tenant'], ['work_order.view']);
        $this->widget($viewer, 'FN-01')->assertStatus(403);
        $this->details($viewer, 'FN-01', ['month' => '2026-09'])->assertStatus(403);

        $other = $this->makeWorkshop($s['tenant'], $s['bdg']);
        [, $outside] = $this->makeTenantUser($s['tenant'], ['dashboard.finance.view'], ['WORKSHOP' => $other->id]);
        $this->assertSame('0.00', $this->widget($outside, 'FN-01')->json('data.data.totals.total'));
        $this->assertSame([], $this->details($outside, 'FN-01', ['month' => '2026-09', 'vehicle_id' => $s['a']->id])->json('data.data'));
    }

    public function test_fn04_aging_per_permitted_source_with_no_due_bucket(): void
    {
        $s = $this->scenario();
        $po = $this->id();
        $warehouse = $this->makeWarehouse($s['tenant'], $s['jkt']);
        DB::table('purchase_orders')->insert(['id' => $po, 'tenant_id' => $s['tenant']->id, 'po_number' => 'PO-1', 'partner_id' => $s['vendor']->id,
            'delivery_warehouse_id' => $warehouse->id, 'status' => 'RECEIVED', 'created_at' => now(), 'updated_at' => now()]);
        $vendorInvoice = fn (string $number, ?string $due, string $amount) => tap($this->id(), fn ($id) => DB::table('vendor_invoice_references')->insert([
            'id' => $id, 'tenant_id' => $s['tenant']->id, 'partner_id' => $s['vendor']->id, 'purchase_order_id' => $po, 'vendor_invoice_number' => $number,
            'vendor_invoice_date' => '2026-06-01', 'due_date' => $due, 'amount' => $amount, 'created_at' => now(), 'updated_at' => now()]));
        $vendorInvoice('VI-NOTDUE', '2026-10-20', '100.00');
        $vendorInvoice('VI-45', '2026-08-23', '200.00');
        $vendorInvoice('VI-NODUE', null, '300.00');
        $paid = $vendorInvoice('VI-PAID', '2026-07-01', '999.00');
        DB::table('vendor_invoice_payments')->insert(['id' => $this->id(), 'tenant_id' => $s['tenant']->id, 'vendor_invoice_reference_id' => $paid,
            'payment_date' => '2026-07-02', 'amount' => '999.00', 'proof_disk' => 'local', 'proof_path' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        [, $all] = $this->makeTenantUser($s['tenant'], ['vendor_invoice.view', 'workshop_invoice.view', 'external_work_order_invoice.view']);
        $data = $this->widget($all, 'FN-04')->assertOk()->json('data.data');
        $buckets = collect($data['buckets'])->keyBy('bucket');
        $this->assertSame('100.00', $buckets['not_due']['VENDOR_INVOICE']);
        $this->assertSame('200.00', $buckets['d31_60']['VENDOR_INVOICE']);
        // Vendor invoice without due date + the external WO (free-text term) → "no due date".
        $this->assertSame('300.00', $buckets['no_due']['VENDOR_INVOICE']);
        $this->assertSame('2000.00', $buckets['no_due']['EXTERNAL_WO']);
        // The paid Service Invoice is not owed; the cancelled one is not owed; the USD one is reported, not summed.
        $this->assertSame('0.00', collect($buckets)->sum(fn ($b) => (float) $b['SERVICE_INVOICE']) == 0 ? '0.00' : 'x');
        $this->assertSame('2600.00', $data['total']);
        $this->assertSame('200.00', $data['overdue']);

        // A source without its permission is not computed at all.
        [, $vendorOnly] = $this->makeTenantUser($s['tenant'], ['vendor_invoice.view']);
        $only = $this->widget($vendorOnly, 'FN-04')->json('data.data');
        $this->assertSame(['VENDOR_INVOICE'], $only['sources']);
        $this->assertArrayNotHasKey('EXTERNAL_WO', $only['buckets'][0]);
        $this->assertSame('600.00', $only['total']);
        [, $none] = $this->makeTenantUser($s['tenant'], ['work_order.view']);
        $this->widget($none, 'FN-04')->assertStatus(403);

        $rows = $this->details($all, 'FN-04', ['bucket' => 'no_due'])->json('data.data');
        $this->assertSame('30 hari', collect($rows)->firstWhere('source', 'EXTERNAL_WO')['payment_term']);
        // A warehouse filter only narrows vendor invoices, so Work Order invoices are left out instead of shown unfiltered.
        $this->assertSame(['VENDOR_INVOICE'], $this->widget($all, 'FN-04', ['warehouse_id' => $warehouse->id])->json('data.data.sources'));
    }

    public function test_fn06_counts_pending_refunds_and_values_accepted_ones_per_month(): void
    {
        $s = $this->scenario();
        $warehouse = $this->makeWarehouse($s['tenant'], $s['jkt']);
        $po = $this->id();
        DB::table('purchase_orders')->insert(['id' => $po, 'tenant_id' => $s['tenant']->id, 'po_number' => 'PO-R', 'partner_id' => $s['vendor']->id,
            'delivery_warehouse_id' => $warehouse->id, 'status' => 'RECEIVED', 'created_at' => now(), 'updated_at' => now()]);
        $return = fn (string $status, ?string $amount, ?string $decided) => DB::table('purchase_returns')->insert(['id' => $this->id(), 'tenant_id' => $s['tenant']->id,
            'return_number' => 'RTV-'.Str::random(4), 'purchase_order_id' => $po, 'partner_id' => $s['vendor']->id, 'warehouse_id' => $warehouse->id,
            'return_option' => 'REFUND', 'status' => $status, 'returned_at' => '2026-09-01 00:00:00', 'refunded_amount' => $amount,
            'vendor_decision' => $amount ? 'ACCEPTED' : null, 'vendor_decided_at' => $decided, 'created_at' => now(), 'updated_at' => now()]);
        $return('REFUND_ACCEPTED', '150.50', '2026-09-30 20:00:00'); // 1 Oct local
        $return('REFUND_ACCEPTED', '49.50', '2026-09-15 00:00:00');
        $return('REFUND_REQUESTED', null, null);
        [, $token] = $this->makeTenantUser($s['tenant'], ['dashboard.finance.view']);

        $data = $this->widget($token, 'FN-06')->assertOk()->json('data.data');
        $months = collect($data['months'])->keyBy('month');
        $this->assertSame('150.50', $months['2026-10']['amount']);
        $this->assertSame('49.50', $months['2026-09']['amount']);
        $this->assertSame('200.00', $data['accepted_total']);
        $this->assertSame(1, $data['pending_requests']);
        $this->assertCount(1, $this->details($token, 'FN-06', ['view' => 'pending'])->json('data.data'));
    }

    private function sum(array $values): string
    {
        return number_format(array_sum(array_map('floatval', $values)), 2, '.', '');
    }
}
