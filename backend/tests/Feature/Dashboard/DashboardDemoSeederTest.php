<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Identity\Models\Tenant;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
use Database\Seeders\BootstrapSeeder;
use Database\Seeders\DashboardDemoSeeder;
use Database\Seeders\DevDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The Tenant Dashboard demo layer: 12 full months + the running month under a controlled clock, one
 * empty month, every scenario the widgets need, totals that reconcile across FN-01/02/03, no row
 * outside ALPHA, and a re-run that changes nothing.
 */
class DashboardDemoSeederTest extends TestCase
{
    use DashboardTestHelpers;

    private const TABLES = ['work_orders', 'purchase_orders', 'goods_receipts', 'vendor_invoice_references', 'vendor_invoice_payments',
        'workshop_invoices', 'workshop_invoice_payments', 'work_order_external_invoices', 'breakdowns', 'stock_transfers', 'stock_movements', 'partners', 'vehicles',
        'work_order_work_intervals', 'work_order_mechanic_assignments', 'workers', 'component_installations', 'mechanic_performance_baselines',
        'component_assets', 'installation_stock_exits'];

    private function counts(): array
    {
        return collect(self::TABLES)->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    public function test_demo_history_covers_every_scenario_reconciles_and_is_idempotent(): void
    {
        $frozen = Carbon::parse('2026-10-15 05:00:00', 'UTC');
        Carbon::setTestNow($frozen);
        $this->seed(DevDemoSeeder::class);
        $this->assertTrue(Carbon::now()->equalTo($frozen), 'the seeder hands the caller its frozen clock back');

        $alpha = Tenant::query()->where('code', 'ALPHA')->firstOrFail();
        $demo = DB::table('work_orders')->where('complaint', 'like', '[DASH-DEMO]%');
        $this->assertSame(0, (clone $demo)->where('tenant_id', '!=', $alpha->id)->count(), 'demo history outside ALPHA');
        $statuses = (clone $demo)->distinct()->pluck('status')->sort()->values()->all();
        foreach (['CANCELLED', 'COMPLETED', 'WAITING_PART', 'EXTERNAL', 'CLOSED'] as $status) {
            $this->assertContains($status, $statuses);
        }
        // Service Invoices: paid, unpaid and one cancelled (maker-checker) then re-recorded.
        $serviceInvoices = DB::table('workshop_invoices')->where('tenant_id', $alpha->id)->where('external_invoice_number', 'like', 'DASH-SI-%');
        $this->assertSame(1, (clone $serviceInvoices)->where('status', 'CANCELLED')->count());
        $paid = DB::table('workshop_invoice_payments')->select('workshop_invoice_id');
        $this->assertTrue((clone $serviceInvoices)->where('status', '!=', 'CANCELLED')->whereIn('id', $paid)->exists());
        $this->assertTrue((clone $serviceInvoices)->where('status', '!=', 'CANCELLED')->whereNotIn('id', $paid)->exists());
        $this->assertTrue(DB::table('vendor_invoice_payments')->where('tenant_id', $alpha->id)->exists());
        $this->assertTrue(DB::table('breakdowns')->where('tenant_id', $alpha->id)->whereNull('resolved_at')->where('severity', 'IMMOBILIZED')->exists());
        $this->assertTrue(DB::table('stock_transfers')->where('tenant_id', $alpha->id)->where('status', 'IN_TRANSIT')->exists());

        // Cross-tenant references never mix.
        foreach ([
            'select count(*) c from work_orders wo join vehicles v on v.id = wo.vehicle_id where v.tenant_id <> wo.tenant_id',
            'select count(*) c from purchase_orders p join partners x on x.id = p.partner_id where x.tenant_id <> p.tenant_id',
            'select count(*) c from stock_movements m join warehouses w on w.id = m.warehouse_id where w.tenant_id <> m.tenant_id',
            'select count(*) c from workshop_invoices i join work_orders wo on wo.id = i.work_order_id where wo.tenant_id <> i.tenant_id',
            'select count(*) c from work_order_external_invoices i join work_orders wo on wo.id = i.work_order_id where wo.tenant_id <> i.tenant_id',
            'select count(*) c from warehouse_stocks where quantity_on_hand < 0',
        ] as $sql) {
            $this->assertSame(0, (int) DB::selectOne($sql)->c, $sql);
        }

        // FN-01 over the dashboard API: 13 months (12 full + running), March 2026 (7 months ago) empty,
        // and the month sum equals the FN-02 and FN-03 totals.
        $token = $this->postJson('/api/v1/auth/login', ['email' => 'alpha.admin@optifleet.test', 'password' => 'password'])->assertOk()->json('data.token');
        $fn01 = $this->widget($token, 'FN-01', ['months' => 12])->assertOk()->json('data.data');
        $this->assertCount(13, $fn01['months']);
        $this->assertSame('2025-10', $fn01['months'][0]['month']);
        $this->assertSame('2026-10', $fn01['months'][12]['month']);
        $this->assertTrue($fn01['months'][12]['is_current']);
        $byMonth = collect($fn01['months'])->keyBy('month');
        $this->assertSame('0.00', $byMonth['2026-03']['total']);
        foreach (['2025-10', '2026-02', '2026-06', '2026-09'] as $month) {
            $this->assertTrue(BigDecimal::of($byMonth[$month]['total'])->isPositive(), "{$month} has no service cost");
        }
        foreach (['PARTS', 'EXTERNAL_SERVICE', 'EXTERNAL_WO'] as $source) {
            $this->assertTrue(BigDecimal::of($fn01['totals'][$source])->isPositive(), "no {$source} cost");
        }
        $sum = collect($fn01['months'])->reduce(fn (BigDecimal $c, $m) => $c->plus($m['total']), BigDecimal::zero());
        $this->assertSame((string) $sum->toScale(2), $fn01['totals']['total']);
        $this->assertSame($fn01['totals']['total'], $this->widget($token, 'FN-03', ['months' => 12])->json('data.data.totals.total'));

        $aging = $this->widget($token, 'FN-04')->assertOk()->json('data.data.buckets');
        $this->assertGreaterThan(0, collect($aging)->whereIn('bucket', ['d1_30', 'd31_60', 'd61_90', 'd90_plus'])->sum('count'), 'no overdue payable');
        $this->assertGreaterThan(0, collect($aging)->firstWhere('bucket', 'not_due')['count']);

        // Operations KPIs: work intervals recorded by the transitions, rework cycles, one running WO.
        $demoIds = (clone $demo)->select('id');
        $intervals = DB::table('work_order_work_intervals')->whereIn('work_order_id', $demoIds);
        $this->assertGreaterThan(20, (clone $intervals)->count());
        $this->assertSame(1, (clone $intervals)->whereNull('ended_at')->count(), 'exactly the running Work Order has an open interval');
        $this->assertSame(3, (int) (clone $intervals)->max('cycle'), 'two rework cycles on one Work Order');
        $this->assertTrue((clone $intervals)->where('end_to_status', 'WAITING_PART')->exists());
        $this->assertSame(0, (clone $intervals)->where('tenant_id', '!=', $alpha->id)->count());
        // Mechanics: assistants, a hand-over (two PRIMARY rows on one WO), a rate change kept as snapshots, one mechanic without a rate.
        $assignments = DB::table('work_order_mechanic_assignments as a')->join('workers as w', 'w.id', '=', 'a.worker_id')->whereIn('a.work_order_id', $demoIds);
        $this->assertGreaterThanOrEqual(6, (clone $assignments)->distinct()->count('a.worker_id'));
        $this->assertTrue((clone $assignments)->where('a.role', 'ASSISTANT')->exists());
        $this->assertTrue((clone $assignments)->where('a.role', 'PRIMARY')->groupBy('a.work_order_id')->havingRaw('count(*) > 1')->select('a.work_order_id')->exists());
        $this->assertEquals(['55000.0000', '60000.0000'], (clone $assignments)->where('w.employee_code', 'DASH-JKT-M1')->distinct()->orderBy('a.hourly_rate_snapshot')->pluck('a.hourly_rate_snapshot')->all());
        $this->assertTrue((clone $assignments)->whereNull('a.hourly_rate_snapshot')->exists());
        // Thresholds: set, intentionally 0, and not set. Baseline: CORRECTIVE set, PREVENTIVE not set.
        $jkt = DB::table('warehouse_stocks as ws')->join('warehouses as wh', 'wh.id', '=', 'ws.warehouse_id')->join('products as p', 'p.id', '=', 'ws.product_id')
            ->where('wh.code', 'ALPHA-JKT-WH1')->pluck('ws.reorder_point', 'p.name');
        $this->assertEquals(80, $jkt['Brake Pad Set (Front)']);
        $this->assertSame('0.0000', (string) $jkt['Engine Oil Filter']);
        $this->assertTrue(DB::table('warehouse_stocks')->where('tenant_id', $alpha->id)->whereNull('reorder_point')->exists());
        $this->assertSame(['CORRECTIVE'], DB::table('mechanic_performance_baselines')->where('tenant_id', $alpha->id)->pluck('maintenance_type')->all());
        $this->assertSame(0, DB::table('mechanic_performance_baselines')->where('tenant_id', '!=', $alpha->id)->count(), 'no baseline outside the demo tenant');

        // FN-07 reconciles with FN-08 on the same basis; labor and running labor are visible.
        $fn07 = $this->widget($token, 'FN-07', ['months' => 12])->assertOk()->json('data');
        $fn08 = $this->widget($token, 'FN-08', ['months' => 12])->assertOk()->json('data.data');
        $this->assertSame($fn07['data']['totals'], $fn08['totals']);
        foreach (['PARTS', 'LABOR', 'EXTERNAL_PAID'] as $component) {
            $this->assertTrue(BigDecimal::of($fn07['data']['totals'][$component])->isPositive(), "no {$component} cost");
        }
        $codes = collect($fn07['limitations'])->pluck('code');
        $this->assertContains('dashboard.limitations.laborRateMissing', $codes);
        $this->assertContains('dashboard.limitations.laborRunning', $codes);

        // WS-07: the Jakarta lead mechanic has enough CORRECTIVE samples against the demo baseline.
        $ws07 = $this->widget($token, 'WS-07', ['months' => 12, 'maintenance_type' => 'CORRECTIVE'])->assertOk()->json('data.data');
        $this->assertSame('6.00', $ws07['baseline_hours']);
        $lead = collect($ws07['mechanics'])->firstWhere('employee_code', 'DASH-JKT-M1');
        $this->assertGreaterThanOrEqual(5, $lead['valid_samples']);
        $this->assertContains($lead['status'], ['MEETS', 'ABOVE']);
        $this->assertNull($this->widget($token, 'WS-07', ['months' => 12, 'maintenance_type' => 'PREVENTIVE'])->json('data.data.baseline_hours'));

        // FL-07: the moved battery is installed once, on the Bandung truck only.
        $battery = DB::table('component_assets')->where('tenant_id', $alpha->id)->where('serial_number', 'DASH-BAT-01')->first();
        $this->assertSame(2, DB::table('component_installations')->where('component_asset_id', $battery->id)->count());
        $vehicleIds = DB::table('vehicles')->where('tenant_id', $alpha->id)->pluck('id', 'registration_number');
        $installed = fn ($reg) => collect($this->details($token, 'FL-07', ['vehicle_id' => $vehicleIds[$reg], 'per_page' => 100])->assertOk()->json('data.data'))->where('asset_id', $battery->id)->count();
        $this->assertSame(0, $installed('B 4101 ALP'));
        $this->assertSame(1, $installed('D 4102 ALP'));

        // WH-02: the intentional 0 is a set threshold (never "not set"); unset rows are listed as NOT_SET.
        $notSet = collect($this->details($token, 'WH-02', ['state' => 'NOT_SET', 'per_page' => 100])->assertOk()->json('data.data'));
        $this->assertFalse($notSet->contains(fn ($r) => $r['product_name'] === 'Engine Oil Filter' && $r['warehouse_name'] === DB::table('warehouses')->where('code', 'ALPHA-JKT-WH1')->value('name')));
        $this->assertTrue($notSet->isNotEmpty());

        // Serialized installations settle with the ledger exactly once (fresh seed): every demo installation has one exit
        // record, direct and Work-Order-issued paths both exist, and the reconciliation finds nothing left to correct.
        $exits = DB::table('installation_stock_exits')->where('tenant_id', $alpha->id);
        $this->assertGreaterThan(0, (clone $exits)->where('source', 'DIRECT_ISSUE')->count());
        $this->assertGreaterThan(0, (clone $exits)->where('source', 'WO_ISSUE')->count());
        $this->assertSame(0, (int) DB::selectOne('select count(*) c from (select installation_id from installation_stock_exits group by installation_id having count(*) > 1) x')->c);
        $this->assertSame(
            (clone $exits)->where('source', 'DIRECT_ISSUE')->count(),
            DB::table('stock_movements')->where('tenant_id', $alpha->id)->where('movement_type', 'ISSUE')->whereIn('reference_type', [\App\Domain\ComponentAsset\Models\ComponentInstallation::class, \App\Domain\Tire\Models\TireInstallation::class])->count(),
            'one ISSUE movement per direct installation, none for Work-Order-issued ones'
        );
        $plan = app(\App\Domain\Inventory\Services\SerializedStockReconciliationService::class)->plan($alpha->id)['tenants'][0];
        $this->assertSame(0, $plan['summary']['PROVABLE_UNDEDUCTED'], 'no installation made through the services leaves the ledger behind');
        $this->assertSame([], collect($plan['balance'])->where('ledger_on_hand', '!=', null)->filter(fn ($b) => $b['explained_by_provable_undeducted'] > 0)->values()->all());

        // Work Orders that predate work-time tracking: unavailable / partial, never 0 and never averaged.
        $legacy = DB::table('work_orders')->where('tenant_id', $alpha->id)->where('complaint', 'like', '%legacy work%')->pluck('id');
        $this->assertCount(2, $legacy);
        $this->assertSame(1, DB::table('work_order_work_intervals')->whereIn('work_order_id', $legacy)->count(), 'only the second interval of the partial one remains');
        $fn07a = $this->widget($token, 'FN-07', ['months' => 3])->assertOk()->json('data');
        $this->assertContains('dashboard.limitations.workTimeUnavailable', array_column($fn07a['limitations'], 'code'));
        $this->assertContains('dashboard.limitations.workTimePartial', array_column($fn07a['limitations'], 'code'));
        $this->assertSame('PARTIAL', $fn07a['basis']['completeness']['status']);
        $jkt = collect($this->widget($token, 'FN-07', ['months' => 3])->json('data.data.vehicles'))->firstWhere('registration_number', 'B 4101 ALP');
        $this->assertFalse($jkt['cost_complete']);

        // Used stock and zero-cost stock are pending valuation, apart from the valued total.
        $fn05 = $this->widget($token, 'FN-05')->assertOk()->json('data');
        $this->assertGreaterThan(0, $fn05['data']['pending_valuation']['used_skus']);
        $this->assertGreaterThan(0, $fn05['data']['pending_valuation']['no_cost_skus']);

        // Re-run: nothing duplicated.
        $before = $this->counts();
        $this->seed(DashboardDemoSeeder::class);
        $this->assertSame($before, $this->counts());
        Carbon::setTestNow();
    }

    public function test_production_bootstrap_adds_the_permission_but_never_a_baseline_or_threshold(): void
    {
        $this->seed(BootstrapSeeder::class);
        $this->seed(BootstrapSeeder::class);

        $this->assertSame(1, DB::table('permissions')->where('name', 'mechanic_baseline.manage')->count());
        $this->assertSame(0, DB::table('mechanic_performance_baselines')->count());
        $this->assertSame(0, DB::table('warehouse_stocks')->whereNotNull('reorder_point')->count());
        $this->assertSame(0, DB::table('work_orders')->where('complaint', 'like', '[DASH-DEMO]%')->count());
    }
}
