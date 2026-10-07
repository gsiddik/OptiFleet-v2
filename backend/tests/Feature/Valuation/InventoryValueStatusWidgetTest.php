<?php

namespace Tests\Feature\Valuation;

use App\Domain\ProductMaster\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Feature\Dashboard\DashboardTestHelpers;
use Tests\TestCase;

/**
 * FN-05 separates verified value from recorded-but-unverified value, verified zero and not-valued stock: an unverified
 * zero is never a legitimate zero, a partial total is flagged, and used / not-valued stock never carries a value.
 */
class InventoryValueStatusWidgetTest extends TestCase
{
    use DashboardTestHelpers;

    private array $s;

    private function scenario(): array
    {
        $tenant = $this->makeTenant(['code' => 'IVS-'.Str::random(4)]);
        $this->grantModules($tenant, ['INVENTORY', 'TIRE', 'PROCUREMENT']);
        $branch = $this->makeBranch($tenant);
        $wh1 = $this->makeWarehouse($tenant, $branch);
        $wh2 = $this->makeWarehouse($tenant, $branch);
        $pcs = $this->makeUom(['code' => 'PCS-'.Str::random(3)]);
        $ltr = $this->makeUom(['code' => 'LTR-'.Str::random(3)]);

        return $this->s = compact('tenant', 'branch', 'wh1', 'wh2', 'pcs', 'ltr');
    }

    private function stock(Product $p, $wh, string $qty, string $cost, ?string $status, ?string $basis = null): void
    {
        DB::table('warehouse_stocks')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->s['tenant']->id, 'warehouse_id' => $wh->id, 'product_id' => $p->id,
            'quantity_on_hand' => $qty, 'average_unit_cost' => $cost, 'valuation_status' => $status, 'valuation_basis' => $basis, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function product(string $name, string $type, $uom): Product
    {
        return $this->makeProduct($this->s['tenant'], null, $uom, ['name' => $name, 'product_type' => $type]);
    }

    public function test_every_valuation_status_lands_in_its_own_bucket_and_the_total_is_flagged_partial(): void
    {
        $s = $this->scenario();
        $this->stock($this->product('Verified part', 'SPARE_PART', $s['pcs']), $s['wh1'], '4', '1000.0000', 'VERIFIED', 'PO_UNIT_PRICE');
        $this->stock($this->product('Unverified part', 'SPARE_PART', $s['pcs']), $s['wh1'], '2', '500.0000', 'UNVERIFIED', 'SOURCE_NOT_VERIFIED');
        $this->stock($this->product('Mixed part', 'SPARE_PART', $s['pcs']), $s['wh2'], '3', '200.0000', 'MIXED', 'MIXED_SOURCES');
        $this->stock($this->product('Free oil', 'CONSUMABLE', $s['ltr']), $s['wh1'], '7', '0.0000', 'UNVERIFIED'); // free goods without a basis: not a verified zero
        $this->stock($this->product('Documented donation', 'CONSUMABLE', $s['pcs']), $s['wh2'], '5', '0.0000', 'VERIFIED_ZERO', 'DONATION_DOCUMENTED');
        $this->stock($this->product('Not valued tool', 'TOOL', $s['pcs']), $s['wh2'], '2', '900.0000', 'NOT_VALUED', 'VALUATION_NOT_PERFORMED');
        $this->stock($this->product('Legacy', 'SPARE_PART', $s['pcs']), $s['wh1'], '1', '100.0000', null); // pre-status row: unverified, never verified
        $tire = $this->product('Tire', 'TIRE', $s['pcs']);
        DB::table('used_tire_stocks')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $s['tenant']->id, 'warehouse_id' => $s['wh1']->id, 'product_id' => $tire->id, 'quantity_on_hand' => 3, 'created_at' => now(), 'updated_at' => now()]);
        [, $token] = $this->makeTenantUser($s['tenant'], ['inventory.view', 'dashboard.finance.view']);

        $res = $this->widget($token, 'FN-05')->assertOk()->json('data');
        $d = $res['data'];

        $this->assertSame('4000.00', $d['verified_value'], 'only VERIFIED balances');
        $this->assertSame('1100.00', $d['unverified_value'], '2×500 + legacy 1×100: recorded, never verified');
        $this->assertSame('600.00', $d['mixed_value']);
        $this->assertSame('5700.00', $d['total'], 'compat: recorded value of positive-cost balances, excluding NOT_VALUED');
        $this->assertSame(['VERIFIED' => 1, 'UNVERIFIED' => 2, 'MIXED' => 1], $d['status_balances']);
        $this->assertSame(1, $d['verified_zero']['balances']);
        $this->assertSame('5.0000', $d['verified_zero']['quantities'][0]['quantity']);
        $pending = collect($d['pending_valuation']['quantities']);
        $this->assertSame(['NOT_VALUED', 'NO_UNIT_COST', 'USED_STOCK'], $pending->pluck('reason')->unique()->sort()->values()->all());
        $this->assertSame('7.0000', $pending->firstWhere('reason', 'NO_UNIT_COST')['quantity'], 'free goods stay unverified: quantity, no value');
        $this->assertSame('2.0000', $pending->firstWhere('reason', 'NOT_VALUED')['quantity']);
        $this->assertSame('3.0000', $pending->firstWhere('reason', 'USED_STOCK')['quantity']);
        $this->assertSame('PARTIAL', $d['verification']['status'], 'a partial figure is flagged');
        $this->assertSame([8, 2], [$d['verification']['total'], $d['verification']['valid']]);
        $this->assertContains('dashboard.limitations.valuationUnverified', array_column($res['limitations'], 'code'));
        $this->assertSame('PARTIAL', $res['basis']['completeness']['status']);

        // Breakdown by Item Type: the verified part of SPARE_PART is only the verified balance.
        $spare = collect($d['item_types'])->firstWhere('item_type', 'SPARE_PART');
        $this->assertSame(['4000.00', '1100.00', '600.00'], [$spare['verified_value'], $spare['unverified_value'], $spare['mixed_value']]);
        $this->assertNull(collect($d['item_types'])->firstWhere('item_type', 'TOOL'), 'a not-valued balance never appears with a value');
        $this->assertNull(collect($d['item_types'])->firstWhere('item_type', 'TIRE'));
    }

    public function test_drill_down_shows_status_basis_and_why_a_row_has_no_value(): void
    {
        $s = $this->scenario();
        $this->stock($this->product('Verified part', 'SPARE_PART', $s['pcs']), $s['wh1'], '4', '1000.0000', 'VERIFIED', 'PO_UNIT_PRICE');
        $this->stock($this->product('Unverified part', 'SPARE_PART', $s['pcs']), $s['wh1'], '2', '500.0000', 'UNVERIFIED', 'SOURCE_NOT_VERIFIED');
        $this->stock($this->product('Free oil', 'CONSUMABLE', $s['ltr']), $s['wh1'], '7', '0.0000', 'UNVERIFIED');
        $this->stock($this->product('Donation', 'CONSUMABLE', $s['pcs']), $s['wh1'], '5', '0.0000', 'VERIFIED_ZERO', 'DONATION_DOCUMENTED');
        [, $token] = $this->makeTenantUser($s['tenant'], ['inventory.view', 'dashboard.finance.view']);

        $rows = collect($this->details($token, 'FN-05', ['valuation_status' => 'UNVERIFIED'])->assertOk()->json('data.data'));
        $this->assertSame(['Unverified part'], $rows->pluck('product_name')->all());
        $this->assertSame(['UNVERIFIED', 'SOURCE_NOT_VERIFIED', '1000.00'], [$rows[0]['valuation_status'], $rows[0]['valuation_basis'], $rows[0]['value']]);
        $this->details($token, 'FN-05', ['valuation_status' => 'BOGUS'])->assertStatus(422);

        $pending = collect($this->details($token, 'FN-05', ['view' => 'pending_valuation'])->assertOk()->json('data.data'))->keyBy('product_name');
        $this->assertSame(['NO_UNIT_COST', 'UNVERIFIED', null], [$pending['Free oil']['reason'], $pending['Free oil']['valuation_status'], $pending['Free oil']['value']], 'a zero cost is "no unit cost", not value 0');
        $this->assertSame(['VERIFIED_ZERO', '0.00'], [$pending['Donation']['reason'], $pending['Donation']['value']], 'only a documented zero is a value of 0');
    }

    public function test_warehouse_scope_permission_and_tenant_isolation(): void
    {
        $s = $this->scenario();
        $this->stock($this->product('A', 'SPARE_PART', $s['pcs']), $s['wh1'], '2', '100.0000', 'VERIFIED');
        $this->stock($this->product('B', 'SPARE_PART', $s['pcs']), $s['wh2'], '2', '300.0000', 'UNVERIFIED');
        [, $scoped] = $this->makeTenantUser($s['tenant'], ['inventory.view', 'dashboard.finance.view'], ['WAREHOUSE' => $s['wh1']->id]);
        [, $noFinance] = $this->makeTenantUser($s['tenant'], ['inventory.view']);
        $d = $this->widget($scoped, 'FN-05')->assertOk()->json('data.data');
        $this->assertSame(['200.00', '0.00', 'COMPLETE'], [$d['verified_value'], $d['unverified_value'], $d['verification']['status']], 'the other warehouse is invisible');
        $this->widget($noFinance, 'FN-05')->assertStatus(403);

        $other = $this->makeTenant(['code' => 'OTH-'.Str::random(4)]);
        $this->grantModules($other, ['INVENTORY']);
        [, $ot] = $this->makeTenantUser($other, ['inventory.view', 'dashboard.finance.view']);
        $this->assertSame('0.00', $this->widget($ot, 'FN-05')->assertOk()->json('data.data.total'));
    }

    public function test_legacy_dashboard_total_is_split_by_verification(): void
    {
        $s = $this->scenario();
        $this->stock($this->product('A', 'SPARE_PART', $s['pcs']), $s['wh1'], '5', '10.0000', 'VERIFIED');
        $this->stock($this->product('B', 'SPARE_PART', $s['pcs']), $s['wh1'], '3', '10.0000', 'UNVERIFIED');
        [, $token] = $this->makeTenantUser($s['tenant'], ['inventory.view', 'dashboard.finance.view']);
        $data = $this->getJson('/api/v1/app/dashboard', $this->authHeaders($token))->assertOk()->json('data');
        $this->assertSame('80.00', $data['inventory_total_value'], 'unchanged recorded total');
        $this->assertSame(['50.00', '30.00', 'PARTIAL'], [$data['inventory_value_basis']['verified_value'], $data['inventory_value_basis']['unverified_recorded_value'], $data['inventory_value_basis']['completeness']]);
    }
}
