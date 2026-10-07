<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Inventory\Services\InventoryService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Trend and supporting widgets (FL-04/05, WS-03/04, WH-04/05, PR-03/04, TR-04) and the Action Center (AL-01). */
class DashboardTrendWidgetsTest extends TestCase
{
    use DashboardTestHelpers;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function uuid(): string
    {
        return (string) Str::uuid();
    }

    public function test_fl04_fl05_breakdowns_per_local_month_and_top_vehicles(): void
    {
        Carbon::setTestNow('2026-10-07 03:00:00');
        $tenant = $this->makeTenant(['timezone' => 'Asia/Jakarta']);
        $this->grantModules($tenant, ['VEHICLE', 'MAINTENANCE']);
        $branch = $this->makeBranch($tenant);
        $cat = $this->makeVehicleCategory();
        $a = $this->makeVehicle($tenant, $branch, $cat, ['registration_number' => 'B 1 A']);
        $b = $this->makeVehicle($tenant, $branch, $cat, ['registration_number' => 'B 2 B']);
        $bd = fn ($v, $at, $sev) => DB::table('breakdowns')->insert(['id' => $this->uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id,
            'vehicle_id' => $v->id, 'description' => 'x', 'severity' => $sev, 'status' => 'RESOLVED', 'reported_at' => $at, 'resolved_at' => $at,
            'created_at' => now(), 'updated_at' => now()]);
        $bd($a, '2026-08-31 17:30:00', 'MAJOR');       // 1 Sep 00:30 Jakarta
        $bd($a, '2026-09-10 00:00:00', 'IMMOBILIZED');
        $bd($b, '2026-07-01 00:00:00', 'MINOR');
        $bd($b, '2025-01-01 00:00:00', 'MINOR');       // outside the window
        [, $token] = $this->makeTenantUser($tenant, ['breakdown.view']);

        $months = collect($this->widget($token, 'FL-04')->assertOk()->json('data.data.months'))->keyBy('month');
        $this->assertSame(0, $months['2026-08']['total']);
        $this->assertSame(['MINOR' => 0, 'MAJOR' => 1, 'IMMOBILIZED' => 1], array_intersect_key($months['2026-09'], array_flip(['MINOR', 'MAJOR', 'IMMOBILIZED'])));
        $this->assertSame(3, $this->widget($token, 'FL-04')->json('data.data.total'));

        $top = $this->widget($token, 'FL-05')->json('data.data.vehicles');
        $this->assertSame(['B 1 A', 'B 2 B'], array_column($top, 'registration_number'));
        $this->assertSame([2, 1], array_column($top, 'total'));
        $this->assertCount(2, $this->details($token, 'FL-05', ['vehicle_id' => $a->id])->json('data.data'));
    }

    public function test_ws03_ws04_completed_by_type_and_median_turnaround(): void
    {
        Carbon::setTestNow('2026-10-07 03:00:00');
        $tenant = $this->makeTenant();
        $this->grantModules($tenant, ['VEHICLE', 'WORK_ORDER']);
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory());
        $done = function (string $type, ?string $start, string $end, string $status = 'COMPLETED') use ($tenant, $branch, $workshop, $vehicle) {
            $wo = $this->makeWorkOrder($tenant, $branch, $workshop, $vehicle, ['status' => $status, 'maintenance_type' => $type]);
            DB::table('work_orders')->where('id', $wo->id)->update(['started_at' => $start, 'completed_at' => $end]);
        };
        $done('PREVENTIVE', '2026-09-01 08:00:00', '2026-09-01 10:00:00');            // 2 h
        $done('CORRECTIVE', '2026-09-02 08:00:00', '2026-09-02 14:00:00', 'CLOSED');  // 6 h
        $done('CORRECTIVE', '2026-09-03 08:00:00', '2026-09-04 08:00:00');            // 24 h
        $done('PREVENTIVE', null, '2026-09-05 08:00:00');                             // not measurable
        $done('CORRECTIVE', '2026-09-06 08:00:00', '2026-09-06 09:00:00', 'CANCELLED');
        [, $token] = $this->makeTenantUser($tenant, ['work_order.view']);

        $sep = collect($this->widget($token, 'WS-03')->json('data.data.months'))->firstWhere('month', '2026-09');
        $this->assertSame(4, $sep['total']);
        $this->assertSame(2, $sep['PREVENTIVE']);
        $this->assertSame(2, $sep['CORRECTIVE']);

        $ws04 = $this->widget($token, 'WS-04')->json('data');
        $this->assertSame(6, (int) $ws04['data']['median_hours']);
        $this->assertSame(3, $ws04['data']['work_orders']);
        $this->assertSame(1, $ws04['data']['not_measurable']);
        $this->assertSame('dashboard.limitations.turnaroundWithoutStart', $ws04['limitations'][0]['code']);
    }

    public function test_wh04_separates_in_and_out_and_reports_unvalued_and_opname_wh05_slow_and_no_history(): void
    {
        Carbon::setTestNow('2026-10-07 03:00:00');
        $tenant = $this->makeTenant();
        $this->grantModules($tenant, ['INVENTORY']);
        $warehouse = $this->makeWarehouse($tenant, $this->makeBranch($tenant));
        $inventory = app(InventoryService::class);
        $fresh = $this->makeProduct($tenant, null, null, ['name' => 'Fresh']);
        $old = $this->makeProduct($tenant, null, null, ['name' => 'Old']);
        $legacy = $this->makeProduct($tenant, null, null, ['name' => 'Legacy']);
        $inventory->receive($warehouse, $fresh, 10, 5, 'RECEIPT', null, null, null);       // in 50
        $inventory->transferOut($warehouse, $fresh, 2, null, null, null);                  // out 2 × 5 = 10
        $inventory->adjust($warehouse, $fresh, 1, 'MINUS', null, 'count');                 // out, no unit cost
        $inventory->postOpnameVariance($warehouse, $fresh, -1, null, 'opname');            // direction not recorded
        $inventory->receive($warehouse, $old, 4, 25, 'RECEIPT', null, null, null);
        DB::table('stock_movements')->where('product_id', $old->id)->update(['occurred_at' => '2026-05-01 00:00:00']);
        DB::table('warehouse_stocks')->insert(['id' => $this->uuid(), 'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_id' => $legacy->id,
            'quantity_on_hand' => 3, 'quantity_reserved' => 0, 'average_unit_cost' => 7, 'created_at' => now(), 'updated_at' => now()]);
        [, $token] = $this->makeTenantUser($tenant, ['inventory.view', 'dashboard.finance.view']);

        $wh04 = $this->widget($token, 'WH-04')->assertOk()->json('data');
        $oct = collect($wh04['data']['months'])->firstWhere('month', '2026-10');
        $this->assertSame('50.00', $oct['value_in']);
        $this->assertSame('10.00', $oct['value_out']);
        $this->assertSame('100.00', collect($wh04['data']['months'])->firstWhere('month', '2026-05')['value_in']);
        $this->assertEqualsCanonicalizing(['dashboard.limitations.unvaluedMovements', 'dashboard.limitations.opnameExcluded'], array_column($wh04['limitations'], 'code'));
        $rows = collect($this->details($token, 'WH-04', ['month' => '2026-10'])->json('data.data'))->keyBy('movement_type');
        $this->assertSame('OUT', $rows['ADJUSTMENT_MINUS']['direction']);
        $this->assertSame(1, $rows['ADJUSTMENT_MINUS']['unvalued']);
        $this->assertSame('UNKNOWN', $rows['STOCK_OPNAME']['direction']);

        $wh05 = $this->widget($token, 'WH-05')->json('data.data');
        $this->assertSame(['count' => 1, 'value' => '100.00'], $wh05['slow']);
        $this->assertSame(['count' => 1, 'value' => '21.00'], $wh05['no_history']);
        $this->assertSame('Old', $wh05['items'][0]['product_name']);

        [, $noFinance] = $this->makeTenantUser($tenant, ['inventory.view']);
        $this->widget($noFinance, 'WH-04')->assertStatus(403);
        $this->widget($noFinance, 'WH-05')->assertStatus(403);
    }

    public function test_pr03_committed_value_and_pr04_vendor_rates_with_denominators(): void
    {
        Carbon::setTestNow('2026-10-07 03:00:00');
        $tenant = $this->makeTenant();
        $this->grantModules($tenant, ['INVENTORY', 'PROCUREMENT']);
        $warehouse = $this->makeWarehouse($tenant, $this->makeBranch($tenant));
        $vendor = $this->makePartner($tenant, ['name' => 'PT Vendor']);
        $po = function (string $status, ?string $date, string $total, ?string $expected = null) use ($tenant, $warehouse, $vendor) {
            $id = $this->uuid();
            DB::table('purchase_orders')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'po_number' => 'PO-'.Str::random(5), 'partner_id' => $vendor->id,
                'delivery_warehouse_id' => $warehouse->id, 'status' => $status, 'order_date' => $date, 'expected_delivery_date' => $expected,
                'total' => $total, 'created_at' => now(), 'updated_at' => now()]);

            return $id;
        };
        $p1 = $po('ISSUED', '2026-09-03', '1000.50', '2026-09-10');
        $p2 = $po('RECEIVED', '2026-09-20', '499.50', '2026-09-25');
        $po('DRAFT', '2026-09-21', '777.00');
        $po('CANCELLED', '2026-09-22', '888.00');
        $po('APPROVED', null, '50.00');
        $p3 = $po('CLOSED', '2026-08-01', '10.00');
        $gr = function (string $poId, string $at, array $qty) use ($tenant, $warehouse, $vendor) {
            $id = $this->uuid();
            DB::table('goods_receipts')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'gr_number' => 'GR-'.Str::random(5), 'purchase_order_id' => $poId,
                'warehouse_id' => $warehouse->id, 'partner_id' => $vendor->id, 'status' => 'POSTED', 'received_at' => $at, 'created_at' => now(), 'updated_at' => now()]);
            $product = $this->makeProduct($tenant);
            $item = $this->uuid();
            DB::table('purchase_order_items')->insert(['id' => $item, 'purchase_order_id' => $poId, 'product_id' => $product->id, 'quantity_ordered' => 10,
                'unit_price' => 1, 'line_total' => 10, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('goods_receipt_items')->insert(['id' => $this->uuid(), 'goods_receipt_id' => $id, 'product_id' => $product->id, 'purchase_order_item_id' => $item, 'unit_cost' => 1,
                'quantity_accepted' => $qty[0], 'quantity_rejected' => $qty[1], 'quantity_damaged' => $qty[2], 'created_at' => now(), 'updated_at' => now()]);
        };
        $gr($p1, '2026-09-09 10:00:00', [9, 1, 0]);   // on time
        $gr($p2, '2026-09-27 10:00:00', [10, 0, 0]);  // late
        $gr($p3, '2026-08-05 10:00:00', [5, 0, 0]);   // no expected date
        [, $token] = $this->makeTenantUser($tenant, ['purchase_order.view', 'dashboard.finance.view']);

        $pr03 = $this->widget($token, 'PR-03')->assertOk()->json('data');
        $this->assertSame('1500.00', collect($pr03['data']['months'])->firstWhere('month', '2026-09')['amount']);
        $this->assertSame('1510.00', $pr03['data']['total']);
        $this->assertSame('dashboard.limitations.poWithoutOrderDate', $pr03['limitations'][0]['code']);

        $vendorRow = $this->widget($token, 'PR-04')->json('data.data.vendors.0');
        $this->assertSame(3, $vendorRow['receipts']);
        $this->assertSame([1, 2, 1], [$vendorRow['on_time'], $vendorRow['with_due_date'], $vendorRow['without_due_date']]);
        $this->assertEquals(50.0, $vendorRow['on_time_rate']);
        $this->assertEquals(96.0, $vendorRow['accepted_rate']); // 24 / 25
    }

    public function test_al01_only_lists_allowed_sources_and_does_not_alert_a_wo_twice(): void
    {
        Carbon::setTestNow('2026-10-07 03:00:00');
        $tenant = $this->makeTenant();
        $this->grantModules($tenant, ['VEHICLE', 'WORK_ORDER', 'MAINTENANCE']);
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory());
        $old = fn (string $status) => tap($this->makeWorkOrder($tenant, $branch, $workshop, $vehicle, ['status' => $status]),
            fn ($wo) => DB::table('work_orders')->where('id', $wo->id)->update(['created_at' => '2026-09-01 00:00:00']));
        $old('WAITING_PART');
        $old('IN_PROGRESS');
        $old('ON_HOLD');
        DB::table('breakdowns')->insert(['id' => $this->uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicle->id,
            'description' => 'x', 'severity' => 'IMMOBILIZED', 'status' => 'REPORTED', 'reported_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        [, $wo] = $this->makeTenantUser($tenant, ['work_order.view']);
        $items = collect($this->widget($wo, 'AL-01')->assertOk()->json('data.data.items'))->keyBy('type');
        $this->assertSame(1, $items['wo_waiting_parts']['count']);
        $this->assertSame(2, $items['wo_aged']['count'], 'The WO waiting for parts is not alerted again as "aged".');
        $this->assertArrayNotHasKey('breakdown_immobilized', $items->all(), 'No breakdown.view → breakdowns are not computed.');
        $this->assertCount(2, $this->details($wo, 'AL-01', ['type' => 'wo_aged'])->json('data.data'));

        [, $both] = $this->makeTenantUser($tenant, ['work_order.view', 'breakdown.view']);
        $first = $this->widget($both, 'AL-01')->json('data.data.items.0');
        $this->assertSame(['breakdown_immobilized', 'critical', 'FL-03'], [$first['type'], $first['severity'], $first['widget']]);

        [, $none] = $this->makeTenantUser($tenant, ['audit.view']);
        $this->assertNotContains('AL-01', collect($this->getJson('/api/v1/app/dashboard/catalog', $this->authHeaders($none))->json('data.widgets'))->pluck('id')->all());
        $this->widget($none, 'AL-01')->assertStatus(403);
    }

    public function test_tr04_tire_service_cost_by_received_month(): void
    {
        Carbon::setTestNow('2026-10-07 03:00:00');
        $tenant = $this->makeTenant();
        $this->grantModules($tenant, ['TIRE']);
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE']);
        $partner = $this->makePartner($tenant);
        $tire = DB::table('tires')->insertGetId(['id' => $id = $this->uuid(), 'tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => 'T-1',
            'current_status' => 'RETREAD', 'created_at' => now(), 'updated_at' => now()], 'id');
        foreach ([['tire_retreads', '2026-09-15', '300.25'], ['tire_repairs', '2026-09-20', '49.75'], ['tire_retreads', null, '999.00'], ['tire_repairs', '2026-08-01', null]] as $i => [$table, $received, $cost]) {
            DB::table($table)->insert(['id' => $this->uuid(), 'tenant_id' => $tenant->id, 'tire_id' => $id, 'cycle_number' => $i + 1, 'sent_at' => '2026-07-01',
                'received_at' => $received, 'partner_id' => $partner->id, 'cost' => $cost, 'status' => $received ? 'RECEIVED' : 'SENT', 'created_at' => now(), 'updated_at' => now()]);
        }
        [, $token] = $this->makeTenantUser($tenant, ['tire.view', 'dashboard.finance.view']);
        $data = $this->widget($token, 'TR-04')->assertOk()->json('data');
        $sep = collect($data['data']['months'])->firstWhere('month', '2026-09');
        $this->assertSame(['300.25', '49.75', 2], [$sep['RETREAD'], $sep['REPAIR'], $sep['cycles']]);
        $this->assertSame('dashboard.limitations.cyclesWithoutCost', $data['limitations'][0]['code']);
        $this->assertSame(1, $this->widget($token, 'TR-03')->json('data.data.retread'));
    }
}
