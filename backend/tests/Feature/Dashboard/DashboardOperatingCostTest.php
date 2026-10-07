<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Identity\Models\Tenant;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * FN-07 Most Costly Vehicle / FN-08 Cost mix: consumption ledger × issue snapshot, mechanic cost from
 * work intervals × rate snapshot (interval month = month it ends, tenant time zone), external cost
 * actually paid; ranking incl. zero-cost vehicles; drill-down reconciles; permission and scope.
 */
class DashboardOperatingCostTest extends TestCase
{
    use DashboardTestHelpers;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function scenario(): array
    {
        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', 'UTC'));
        $tenant = $this->makeTenant(['code' => 'OPC-'.Str::random(4), 'timezone' => 'Asia/Jakarta']);
        $this->grantModules($tenant, ['VEHICLE', 'WORK_ORDER', 'WORKSHOP', 'INVENTORY']);
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        $catA = $this->makeVehicleCategory();
        $catB = $this->makeVehicleCategory();
        $v1 = $this->makeVehicle($tenant, $branch, $catA, ['registration_number' => 'B 1 OPC']);
        $v2 = $this->makeVehicle($tenant, $branch, $catB, ['registration_number' => 'B 2 OPC']);
        $v3 = $this->makeVehicle($tenant, $branch, $catA, ['registration_number' => 'B 3 OPC']);
        $mechanic = $this->makeWorker($tenant, $branch, $workshop, ['employee_code' => 'OPC-M', 'hourly_rate' => '50000.00']);
        [$user] = $this->makeTenantUser($tenant, []);

        $wo1 = $this->makeWorkOrder($tenant, $branch, $workshop, $v1, ['status' => 'COMPLETED', 'started_at' => '2026-08-31 15:00:00', 'completed_at' => '2026-09-02 03:00:00']);
        // Work 15:00–18:00 UTC on 31 Aug = 22:00 31 Aug – 01:00 1 Sep Jakarta → ends in September.
        $this->interval($wo1, '2026-08-31 15:00:00', '2026-08-31 18:00:00', 1);
        $this->assignment($wo1, $mechanic->id, '50000.0000', '2026-08-31 14:00:00');

        // Issued 4 at 400000 (unit 100000), planned 10, consumed 2 (5 Sep) + 1 (10 Jul); 1 issued unconsumed.
        $product = $this->makeProduct($tenant, null, null, ['name' => 'Brake pad', 'sku' => 'BP-1']);
        $part = (string) Str::uuid();
        DB::table('work_order_planned_parts')->insert(['id' => $part, 'tenant_id' => $tenant->id, 'work_order_id' => $wo1->id, 'product_id' => $product->id,
            'warehouse_id' => $warehouse->id, 'description' => 'Brake pad', 'planned_quantity' => 10, 'issued_quantity' => 4, 'consumed_quantity' => 3,
            'total_cost' => '400000.0000', 'unit_cost_at_issue' => '100000.0000', 'status' => 'ISSUED', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['2026-09-05 02:00:00', 2], ['2026-07-10 02:00:00', 1]] as [$at, $qty]) {
            DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id,
                'product_id' => $product->id, 'movement_type' => 'CONSUME', 'quantity' => $qty, 'unit_cost' => '999.0000',
                'reference_type' => WorkOrderPlannedPart::class, 'reference_id' => $part, 'occurred_at' => $at, 'created_at' => now(), 'updated_at' => now()]);
        }
        // Issue ledger rows never count as cost.
        DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id,
            'product_id' => $product->id, 'movement_type' => 'ISSUE', 'quantity' => -4, 'unit_cost' => '100000.0000',
            'reference_type' => WorkOrderPlannedPart::class, 'reference_id' => $part, 'occurred_at' => '2026-09-04 02:00:00', 'created_at' => now(), 'updated_at' => now()]);

        // Service Invoice on WO1 paid 12 Sep (counts); another invoice paid then cancelled (never counts).
        $partner = $this->makePartner($tenant);
        $paid = $this->serviceInvoice($tenant, $wo1, $partner->id, 'SI-1', '500000.00', 'RECORDED');
        $this->payment($tenant, $paid, '2026-09-12', '500000.00', $user->id);
        $cancelled = $this->serviceInvoice($tenant, $wo1, $partner->id, 'SI-2', '300000.00', 'CANCELLED');
        $this->payment($tenant, $cancelled, '2026-09-13', '300000.00', $user->id);

        // External WO on V2 settled 10 Sep (counts at payment); a billed-only one never counts.
        $wo2 = $this->makeWorkOrder($tenant, $branch, $workshop, $v2, ['status' => 'CLOSED', 'execution_mode' => 'EXTERNAL']);
        DB::table('work_order_external_invoices')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
            'work_order_id' => $wo2->id, 'status' => 'PAID', 'wal_number' => 'WAL-1', 'vendor_invoice_date' => '2026-08-25', 'vendor_invoice_amount' => '4200000.00',
            'payment_date' => '2026-09-10', 'paid_amount' => '4200000.00', 'created_at' => now(), 'updated_at' => now()]);
        $wo3 = $this->makeWorkOrder($tenant, $branch, $workshop, $v2, ['status' => 'EXTERNAL', 'execution_mode' => 'EXTERNAL']);
        DB::table('work_order_external_invoices')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
            'work_order_id' => $wo3->id, 'status' => 'BILLED', 'wal_number' => 'WAL-2', 'vendor_invoice_date' => '2026-09-01', 'vendor_invoice_amount' => '999000.00',
            'created_at' => now(), 'updated_at' => now()]);

        // Another tenant's cost never leaks in.
        $other = $this->makeTenant(['code' => 'OPX-'.Str::random(4)]);
        $ob = $this->makeBranch($other);
        $ows = $this->makeWorkshop($other, $ob);
        $owo = $this->makeWorkOrder($other, $ob, $ows, $this->makeVehicle($other, $ob, $catA), ['status' => 'COMPLETED']);
        $this->interval($owo, '2026-09-01 01:00:00', '2026-09-01 09:00:00', 1);
        $this->assignment($owo, $this->makeWorker($other, $ob, $ows, ['employee_code' => 'OPX-M'])->id, '90000.0000', '2026-09-01 00:00:00');

        return compact('tenant', 'branch', 'workshop', 'v1', 'v2', 'v3', 'catB', 'wo1', 'wo2');
    }

    public function test_most_costly_vehicle_ranks_all_vehicles_and_reconciles_with_cost_mix_and_drilldown(): void
    {
        $s = $this->scenario();
        [, $token] = $this->makeTenantUser($s['tenant'], ['dashboard.finance.view']);

        $fn07 = $this->widget($token, 'FN-07', ['months' => 3])->assertOk()->json('data');
        $this->assertSame('period', $fn07['kind']);
        $this->assertSame(['PARTS' => '300000.00', 'LABOR' => '150000.00', 'EXTERNAL_PAID' => '4700000.00', 'total' => '5150000.00'], $fn07['data']['totals']);
        $this->assertSame(['B 2 OPC', 'B 1 OPC', 'B 3 OPC'], array_column($fn07['data']['vehicles'], 'registration_number'));
        $this->assertSame('0.00', $fn07['data']['vehicles'][2]['total'], 'a vehicle without activity is listed with 0');
        $this->assertSame(3, $fn07['data']['vehicles_total']);
        $this->assertSame(2, $fn07['data']['vehicles_with_cost']);

        // FN-08: same basis; interval ending 01:00 Jakarta on 1 Sep is September labor; July parts stay in July.
        $months = collect($this->widget($token, 'FN-08', ['months' => 3])->assertOk()->json('data.data.months'))->keyBy('month');
        $this->assertSame(['PARTS' => '200000.00', 'LABOR' => '150000.00', 'EXTERNAL_PAID' => '4700000.00', 'total' => '5050000.00'],
            array_intersect_key($months['2026-09'], array_flip(['PARTS', 'LABOR', 'EXTERNAL_PAID', 'total'])));
        $this->assertSame('100000.00', $months['2026-07']['PARTS']);
        $this->assertSame('0.00', $months['2026-08']['total']);
        $this->assertTrue($months['2026-09']['is_current']);

        // Drill-down: ranking → vehicle → Work Order lines, every level sums to the same total.
        $ranking = $this->details($token, 'FN-07', ['months' => 3])->assertOk()->json('data');
        $this->assertSame(3, $ranking['meta']['total']);
        $wos = $this->details($token, 'FN-07', ['months' => 3, 'vehicle_id' => $s['v1']->id])->assertOk()->json('data.data');
        $this->assertEquals([['work_order_id' => $s['wo1']->id, 'total' => '950000.00', 'history_complete' => true]],
            array_map(fn ($r) => array_intersect_key($r, array_flip(['work_order_id', 'total', 'history_complete'])), $wos));
        $lines = collect($this->details($token, 'FN-07', ['months' => 3, 'work_order_id' => $s['wo1']->id])->assertOk()->json('data.data'));
        $this->assertSame(['EXTERNAL_PAID', 'LABOR', 'PARTS', 'PARTS'], $lines->pluck('component')->all());
        $this->assertSame('950000.00', number_format($lines->sum(fn ($l) => (float) $l['amount']), 2, '.', ''));
        $labor = $lines->firstWhere('component', 'LABOR');
        $this->assertSame(['3.00', '50000.00', '2026-09-01'], [$labor['hours'], $labor['effective_rate'], $labor['on']]);
        $this->assertSame('100000.0000', $lines->firstWhere('component', 'PARTS')['unit_cost'], 'issue snapshot, never the ledger or master price');

        // Category filter and month detail of FN-08.
        $this->assertSame('4200000.00', $this->widget($token, 'FN-07', ['months' => 3, 'vehicle_category_id' => $s['catB']->id])->json('data.data.totals.total'));
        $this->assertSame(4, $this->details($token, 'FN-08', ['months' => 3, 'month' => '2026-09'])->assertOk()->json('data.meta.total'));
    }

    public function test_cost_widgets_need_finance_permission_and_follow_workshop_scope(): void
    {
        $s = $this->scenario();
        [, $noFinance] = $this->makeTenantUser($s['tenant'], ['work_order.view']);
        $this->widget($noFinance, 'FN-07')->assertStatus(403);
        $this->details($noFinance, 'FN-07', ['work_order_id' => $s['wo1']->id])->assertStatus(403);

        $otherWorkshop = $this->makeWorkshop($s['tenant'], $s['branch']);
        [, $scoped] = $this->makeTenantUser($s['tenant'], ['dashboard.finance.view'], ['WORKSHOP' => $otherWorkshop->id]);
        $data = $this->widget($scoped, 'FN-07', ['months' => 3])->assertOk()->json('data.data');
        $this->assertSame('0.00', $data['totals']['total']);
        $this->assertSame([], $this->details($scoped, 'FN-07', ['months' => 3, 'work_order_id' => $s['wo1']->id])->json('data.data'));
    }

    // ------------------------------------------------------------------ fixtures

    private function interval(WorkOrder $wo, string $start, ?string $end, int $cycle): void
    {
        DB::table('work_order_work_intervals')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $wo->tenant_id, 'work_order_id' => $wo->id,
            'cycle' => $cycle, 'started_at' => $start, 'ended_at' => $end, 'start_from_status' => 'SCHEDULED', 'end_to_status' => $end ? 'QC_PENDING' : null,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function assignment(WorkOrder $wo, string $workerId, ?string $rate, string $from, ?string $to = null): void
    {
        DB::table('work_order_mechanic_assignments')->insert(['id' => (string) Str::uuid(), 'work_order_id' => $wo->id, 'worker_id' => $workerId,
            'role' => 'PRIMARY', 'hourly_rate_snapshot' => $rate, 'assigned_at' => $from, 'unassigned_at' => $to, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function serviceInvoice(Tenant $tenant, WorkOrder $wo, string $partnerId, string $number, string $amount, string $status): string
    {
        $memo = (string) Str::uuid();
        DB::table('work_order_external_services')->insert(['id' => $memo, 'tenant_id' => $tenant->id, 'work_order_id' => $wo->id,
            'partner_id' => $partnerId, 'description' => 'Machining', 'created_at' => now(), 'updated_at' => now()]);
        $id = (string) Str::uuid();
        DB::table('workshop_invoices')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'work_order_external_service_id' => $memo, 'work_order_id' => $wo->id,
            'partner_id' => $partnerId, 'external_invoice_number' => $number, 'external_invoice_number_normalized' => strtolower($number),
            'invoice_date' => '2026-09-08', 'total_amount' => $amount, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function payment(Tenant $tenant, string $invoiceId, string $date, string $amount, string $userId): void
    {
        DB::table('workshop_invoice_payments')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'workshop_invoice_id' => $invoiceId,
            'payment_date' => $date, 'paid_amount' => $amount, 'evidence_url' => 'x.pdf', 'uploaded_by' => $userId, 'uploaded_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
    }
}
