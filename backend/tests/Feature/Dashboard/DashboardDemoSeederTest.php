<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Identity\Models\Tenant;
use Brick\Math\BigDecimal;
use Carbon\Carbon;
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
        'workshop_invoices', 'workshop_invoice_payments', 'work_order_external_invoices', 'breakdowns', 'stock_transfers', 'stock_movements', 'partners', 'vehicles'];

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

        // Re-run: nothing duplicated.
        $before = $this->counts();
        $this->seed(DashboardDemoSeeder::class);
        $this->assertSame($before, $this->counts());
        Carbon::setTestNow();
    }
}
