<?php

namespace Tests\Feature\Valuation;

use App\Domain\Inventory\Services\SerializedStockReconciliationService;
use App\Domain\Identity\Models\Tenant;
use Carbon\Carbon;
use Database\Seeders\DevDemoSeeder;
use Database\Seeders\ValuationReconciliationDemoSeeder;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The valuation / reconciliation demo layer: every scenario exists once, built through the services, and a rerun changes nothing. */
class ValuationReconciliationDemoSeederTest extends TestCase
{
    private function fingerprint(): array
    {
        return [
            'movements' => DB::table('stock_movements')->count(), 'adjustments' => DB::table('stock_reconciliation_adjustments')->count(),
            'reviews' => DB::table('stock_valuation_reviews')->count(), 'exits' => DB::table('installation_stock_exits')->count(),
            'opnames' => DB::table('stock_opnames')->count(), 'products' => DB::table('products')->where('name', 'like', 'VR Demo%')->count(),
        ];
    }

    public function test_every_scenario_exists_once_and_a_rerun_is_a_no_op(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-15 05:00:00', 'UTC'));
        $this->seed(DevDemoSeeder::class);
        $alpha = Tenant::query()->where('code', 'ALPHA')->firstOrFail();

        // Adjustments: one per workflow state, applied exactly once.
        $this->assertSame(['APPLIED' => 1, 'APPROVED' => 1, 'PENDING_APPROVAL' => 1, 'REJECTED' => 1],
            DB::table('stock_reconciliation_adjustments')->where('tenant_id', $alpha->id)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')->map(fn ($c) => (int) $c)->all());
        $applied = DB::table('stock_reconciliation_adjustments')->where('status', 'APPLIED')->first();
        $this->assertSame(1, DB::table('stock_movements')->where('reference_id', $applied->id)->count());
        $this->assertNotNull($applied->decided_by);
        $this->assertNotSame($applied->proposed_by, $applied->decided_by, 'maker != checker');

        // Reconciliation categories of the demo products are all present.
        $plan = app(SerializedStockReconciliationService::class)->plan($alpha->id)['tenants'][0];
        foreach (['PROVABLE_UNDEDUCTED', 'COVERED_BY_WO_ISSUE', 'RESOLVED_BY_OPNAME', 'AMBIGUOUS_TIRE', 'AMBIGUOUS_NO_RECEIPT', 'CORRECTED_BY_RECONCILIATION'] as $category) {
            $this->assertGreaterThan(0, $plan['summary'][$category], $category);
        }
        $tire = collect($plan['candidates'])->firstWhere('category', 'AMBIGUOUS_TIRE');
        $this->assertSame('-1.0000', $tire['warehouse_evidence'][0]['opname']['variance']);
        $this->assertSame(2, DB::table('stock_opnames')->where('status', 'POSTED')->where('tenant_id', $alpha->id)->count());

        // Valuation scenarios.
        $status = fn (string $name) => DB::table('warehouse_stocks as ws')->join('products as p', 'p.id', '=', 'ws.product_id')->where('p.name', $name)->value('ws.valuation_status');
        $this->assertSame('VERIFIED', $status('VR Demo Priced Hose'));
        $this->assertSame('UNVERIFIED', $status('VR Demo Free Sample Oil'), 'free goods without a basis are not a verified zero');
        $this->assertSame('VERIFIED_ZERO', $status('VR Demo Donated Filter'));
        $this->assertSame('MIXED', $status('VR Demo Mixed Bolt'));
        $this->assertSame('DON-2026-014', DB::table('stock_valuation_reviews')->value('evidence_reference'));
        $this->assertSame('0.0000', DB::table('warehouse_stocks as ws')->join('products as p', 'p.id', '=', 'ws.product_id')->where('p.name', 'VR Demo Donated Filter')->value('ws.average_unit_cost'), 'a review never changes a cost');

        // Rerun: nothing is duplicated.
        $before = $this->fingerprint();
        $this->seed(ValuationReconciliationDemoSeeder::class);
        $this->seed(DevDemoSeeder::class);
        $this->assertSame($before, $this->fingerprint());
    }
}
