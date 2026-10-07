<?php

namespace Tests\Feature\Dashboard;

use App\Domain\ProductMaster\Models\Product;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * WH-06 Most Used, FL-07 Installed Components, FN-05 by Item Type, WH-02 Low Stock, WH-07 Part
 * fulfilment and PR-05 Procurement cycle.
 */
class DashboardInventoryKpiTest extends TestCase
{
    use DashboardTestHelpers;

    private array $s = [];

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function base(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-20 12:00:00', 'UTC'));
        $tenant = $this->makeTenant(['code' => 'INV-'.Str::random(4)]);
        $this->grantModules($tenant, ['VEHICLE', 'WORK_ORDER', 'WORKSHOP', 'INVENTORY', 'TIRE', 'PROCUREMENT']);
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        $other = $this->makeWarehouse($tenant, $branch);
        $pcs = $this->makeUom(['code' => 'PCS-'.Str::random(3)]);
        $ltr = $this->makeUom(['code' => 'LTR-'.Str::random(3)]);
        $category = $this->makeVehicleCategory();
        $this->s = compact('tenant', 'branch', 'workshop', 'warehouse', 'other', 'pcs', 'ltr') + [
            'v1' => $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'B 1 INV']),
            'v2' => $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'B 2 INV']),
        ];
    }

    private function product(string $name, string $type, $uom): Product
    {
        return $this->makeProduct($this->s['tenant'], null, $uom, ['name' => $name, 'product_type' => $type]);
    }

    private function line(string $woId, string $productId, string $condition = 'NEW', string $issued = '4', string $total = '400.0000', ?string $warehouseId = null): string
    {
        $id = (string) Str::uuid();
        DB::table('work_order_planned_parts')->insert(['id' => $id, 'tenant_id' => $this->s['tenant']->id, 'work_order_id' => $woId, 'product_id' => $productId,
            'warehouse_id' => $warehouseId ?? $this->s['warehouse']->id, 'description' => 'x', 'planned_quantity' => $issued, 'issued_quantity' => $issued,
            'consumed_quantity' => $issued, 'total_cost' => $total, 'stock_condition' => $condition, 'status' => 'CONSUMED', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function consume(string $lineId, string $productId, string $qty, string $at, ?string $warehouseId = null): void
    {
        DB::table('stock_movements')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->s['tenant']->id, 'warehouse_id' => $warehouseId ?? $this->s['warehouse']->id,
            'product_id' => $productId, 'movement_type' => 'CONSUME', 'quantity' => $qty, 'reference_type' => WorkOrderPlannedPart::class,
            'reference_id' => $lineId, 'occurred_at' => $at, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function tire(string $productId, string $serial): string
    {
        $id = (string) Str::uuid();
        DB::table('tires')->insert(['id' => $id, 'tenant_id' => $this->s['tenant']->id, 'serial_number' => $serial, 'product_id' => $productId,
            'current_status' => 'INSTALLED', 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    private function install(string $tireId, string $vehicleId, ?string $woId, string $at, ?string $removed = null, string $position = 'P1'): void
    {
        DB::table('tire_installations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->s['tenant']->id, 'tire_id' => $tireId, 'vehicle_id' => $vehicleId,
            'wheel_position' => $position, 'installed_at' => $at, 'work_order_id' => $woId, 'removed_at' => $removed, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_most_used_counts_distinct_work_orders_and_reused_tires_once(): void
    {
        $this->base();
        ['tenant' => $t, 'branch' => $b, 'workshop' => $ws, 'v1' => $v1, 'v2' => $v2] = $this->s;
        $filter = $this->product('Oil filter', 'SPARE_PART', $this->s['pcs']);
        $oil = $this->product('Engine oil', 'CONSUMABLE', $this->s['ltr']);
        $tireProduct = $this->product('Tire 295', 'TIRE', $this->s['pcs']);
        $wo1 = $this->makeWorkOrder($t, $b, $ws, $v1, ['status' => 'COMPLETED']);
        $wo2 = $this->makeWorkOrder($t, $b, $ws, $v2, ['status' => 'COMPLETED']);

        // Filter: WO1 in two entries + WO2 once → Used Times 2 (not 3); oil: one WO, 30 litres.
        $l1 = $this->line($wo1->id, $filter->id);
        $this->consume($l1, $filter->id, '1', '2026-09-02 01:00:00');
        $this->consume($l1, $filter->id, '1', '2026-09-03 01:00:00');
        $this->consume($this->line($wo2->id, $filter->id), $filter->id, '2', '2026-09-04 01:00:00');
        $this->consume($this->line($wo1->id, $oil->id, 'NEW', '30'), $oil->id, '30', '2026-09-02 01:00:00');
        $this->consume($this->line($wo2->id, $oil->id), $oil->id, '5', '2025-01-01 01:00:00'); // outside the period

        // Reused tire on WO2 (no ledger): one event even though it was issued twice; a NEW tire of the
        // same product installed by the same WO is a ledger event, not a second reused one.
        $used = $this->line($wo2->id, $tireProduct->id, 'USED', '1', '0');
        $reusedTire = $this->tire($tireProduct->id, 'REUSED-1');
        foreach ([1, 2] as $issue) {
            DB::table('used_tire_stock_movements')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'warehouse_id' => $this->s['warehouse']->id,
                'product_id' => $tireProduct->id, 'tire_id' => $reusedTire, 'movement_type' => 'ISSUE', 'quantity' => -1, 'balance_after' => 0,
                'reference_type' => WorkOrderPlannedPart::class, 'reference_id' => $used, 'occurred_at' => "2026-09-05 0{$issue}:00:00",
                'created_at' => now(), 'updated_at' => now()]);
        }
        $this->install($reusedTire, $v2->id, $wo2->id, '2026-09-05 02:00:00');
        $newTire = $this->tire($tireProduct->id, 'NEW-1');
        $newLine = $this->line($wo2->id, $tireProduct->id, 'NEW', '1', '1500.0000');
        $this->consume($newLine, $tireProduct->id, '1', '2026-09-05 02:00:00');
        $this->install($newTire, $v2->id, $wo2->id, '2026-09-05 02:00:00', null, 'P2');

        [, $token] = $this->makeTenantUser($t, ['inventory.view']);
        $products = collect($this->widget($token, 'WH-06', ['months' => 3])->assertOk()->json('data.data.products'))->keyBy('product_name');
        $this->assertSame(['used_times' => 2, 'events' => 3, 'quantity' => '4.0000', 'vehicles' => 2],
            array_intersect_key($products['Oil filter'], array_flip(['used_times', 'events', 'quantity', 'vehicles'])));
        $this->assertEquals(['used_times' => 1, 'quantity' => '30.0000', 'uom' => $this->s['ltr']->code],
            array_intersect_key($products['Engine oil'], array_flip(['used_times', 'quantity', 'uom'])));
        $this->assertSame(['used_times' => 1, 'events' => 2, 'quantity' => '2.0000'], array_intersect_key($products['Tire 295'], array_flip(['used_times', 'events', 'quantity'])));
        $this->assertSame('Oil filter', $products->keys()->first(), 'ranked by Used Times, not by quantity');

        $events = $this->details($token, 'WH-06', ['months' => 3, 'product_id' => $tireProduct->id])->assertOk()->json('data.data');
        $this->assertEqualsCanonicalizing(['LEDGER', 'REUSED_TIRE'], array_column($events, 'source'));
        $this->assertSame(['Engine oil'], array_column($this->widget($token, 'WH-06', ['months' => 3, 'item_type' => 'CONSUMABLE'])->json('data.data.products'), 'product_name'));

        // Warehouse scope: another warehouse's user sees nothing.
        [, $scoped] = $this->makeTenantUser($t, ['inventory.view'], ['WAREHOUSE' => $this->s['other']->id]);
        $this->assertSame([], $this->widget($scoped, 'WH-06', ['months' => 3])->json('data.data.products'));
    }

    public function test_installed_components_cost_basis_removal_and_finance_visibility(): void
    {
        $this->base();
        ['tenant' => $t, 'branch' => $b, 'workshop' => $ws, 'v1' => $v1, 'v2' => $v2] = $this->s;
        $tireProduct = $this->product('Tire 11R', 'TIRE', $this->s['pcs']);
        $rimProduct = $this->product('Rim 22.5', 'RIM', $this->s['pcs']);
        $wo = $this->makeWorkOrder($t, $b, $ws, $v1, ['status' => 'COMPLETED']);
        $this->line($wo->id, $tireProduct->id, 'NEW', '2', '3000.0000');               // 1500.00 per tire
        $this->install($this->tire($tireProduct->id, 'T-WO'), $v1->id, $wo->id, '2026-09-01 01:00:00');
        $this->install($this->tire($tireProduct->id, 'T-REG'), $v1->id, null, '2026-01-01 01:00:00', null, 'P2'); // registered: no basis
        $this->install($this->tire($tireProduct->id, 'T-OFF'), $v1->id, $wo->id, '2026-08-01 01:00:00', '2026-08-15 01:00:00', 'P3'); // removed
        // A rim moved from V1 to V2: only the open installation counts.
        $asset = (string) Str::uuid();
        DB::table('component_assets')->insert(['id' => $asset, 'tenant_id' => $t->id, 'product_id' => $rimProduct->id, 'serial_number' => 'RIM-1',
            'purchase_cost' => '800.00', 'current_status' => 'INSTALLED', 'current_vehicle_id' => $v2->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[$v1->id, '2026-07-01 00:00:00', '2026-08-01 00:00:00'], [$v2->id, '2026-08-02 00:00:00', null]] as [$vehicle, $from, $to]) {
            DB::table('component_installations')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'component_asset_id' => $asset, 'vehicle_id' => $vehicle,
                'installed_at' => $from, 'removed_at' => $to, 'created_at' => now(), 'updated_at' => now()]);
        }

        [, $token] = $this->makeTenantUser($t, ['vehicle.view', 'tire.view', 'inventory.view', 'dashboard.finance.view']);
        $data = $this->widget($token, 'FL-07')->assertOk()->json('data.data');
        $this->assertSame(['items' => 3, 'unvalued' => 1, 'value' => '2300.00', 'vehicles' => 2], $data['totals']);
        $types = collect($data['by_type'])->keyBy('item_type');
        $this->assertSame(['items' => 2, 'unvalued' => 1, 'value' => '1500.00'], array_intersect_key($types['TIRE'], array_flip(['items', 'unvalued', 'value'])));
        $this->assertSame('800.00', $types['RIM']['value']);
        $items = collect($this->details($token, 'FL-07', ['vehicle_id' => $v1->id])->assertOk()->json('data.data'))->keyBy('serial_number');
        $this->assertSame(['T-REG', 'T-WO'], $items->keys()->sort()->values()->all());
        $this->assertSame(['1500.00', 'WORK_ORDER_CONSUMPTION'], [$items['T-WO']['cost'], $items['T-WO']['cost_basis']]);
        $this->assertSame([null, 'NO_COST_BASIS'], [$items['T-REG']['cost'], $items['T-REG']['cost_basis']]);

        // Without the finance permission no value leaves the server.
        [, $noFinance] = $this->makeTenantUser($t, ['vehicle.view', 'tire.view', 'inventory.view']);
        $plain = $this->widget($noFinance, 'FL-07')->assertOk()->json('data.data');
        $this->assertFalse($plain['values_visible']);
        $this->assertNull($plain['totals']['value']);
        $this->assertNull(collect($this->details($noFinance, 'FL-07', ['vehicle_id' => $v1->id])->json('data.data'))->firstWhere('serial_number', 'T-WO')['cost']);
    }

    public function test_inventory_value_by_item_type_and_low_stock_threshold_states(): void
    {
        $this->base();
        ['tenant' => $t, 'warehouse' => $wh] = $this->s;
        $stock = function ($product, string $qty, ?string $reorder, string $cost = '10.0000') use ($t, $wh) {
            DB::table('warehouse_stocks')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'warehouse_id' => $wh->id, 'product_id' => $product->id,
                'quantity_on_hand' => $qty, 'reorder_point' => $reorder, 'average_unit_cost' => $cost, 'created_at' => now(), 'updated_at' => now()]);
        };
        $stock($this->product('Tire A', 'TIRE', $this->s['pcs']), '4', null, '1500.0000');           // NOT_SET
        $stock($this->product('Bolt', 'SPARE_PART', $this->s['pcs']), '3', '10');                    // LOW
        $stock($this->product('Nut', 'SPARE_PART', $this->s['pcs']), '0', null);                     // OUT (no threshold)
        $stock($this->product('Washer', 'SPARE_PART', $this->s['pcs']), '5', '0');                   // intentional 0 → NORMAL
        $stock($this->product('Grease', 'CONSUMABLE', $this->s['ltr']), '2', '5');                   // LOW, other UOM

        [, $token] = $this->makeTenantUser($t, ['inventory.view', 'dashboard.finance.view']);
        $fn05 = $this->widget($token, 'FN-05')->assertOk()->json('data.data');
        $types = collect($fn05['item_types'])->keyBy('item_type');
        $this->assertSame(['sku_count' => 1, 'value' => '6000.00'], array_intersect_key($types['TIRE'], array_flip(['sku_count', 'value'])));
        $this->assertSame([['uom' => $this->s['pcs']->code, 'quantity' => '8.0000']], $types['SPARE_PART']['quantities']);
        $this->assertSame(['uom' => $this->s['ltr']->code, 'quantity' => '2.0000'], $types['CONSUMABLE']['quantities'][0]);
        $this->assertSame($fn05['total'], number_format(collect($fn05['item_types'])->sum(fn ($r) => (float) $r['value']), 2, '.', ''));

        $wh02 = $this->widget($token, 'WH-02')->assertOk()->json('data.data');
        $this->assertSame(['OUT' => 1, 'LOW' => 2, 'NOT_SET' => 1], $wh02['by_state']);
        $this->assertFalse($wh02['can_manage_threshold']);
        $pcsRows = $this->widget($token, 'WH-02', ['uom' => $this->s['pcs']->code])->json('data.data.items');
        $this->assertSame(['Nut', 'Bolt'], array_column($pcsRows, 'product_name'), 'least stock first within one UOM');
        $this->assertSame(['7.00', '30.0'], [$pcsRows[1]['shortage'], $pcsRows[1]['ratio']]);
        $this->assertSame(['Tire A'], array_column($this->details($token, 'WH-02', ['state' => 'NOT_SET'])->assertOk()->json('data.data'), 'product_name'));
        $this->assertSame(['OUT' => 1, 'LOW' => 2, 'NORMAL' => 1, 'NOT_SET' => 1], $this->widget($token, 'WH-01')->json('data.data.by_state'));
        [, $manager] = $this->makeTenantUser($t, ['inventory.view', 'inventory.adjust']);
        $this->assertTrue($this->widget($manager, 'WH-02')->json('data.data.can_manage_threshold'));
    }

    public function test_part_fulfilment_and_procurement_cycle(): void
    {
        $this->base();
        ['tenant' => $t, 'branch' => $b, 'workshop' => $ws, 'warehouse' => $wh, 'v1' => $v1] = $this->s;
        $wo = $this->makeWorkOrder($t, $b, $ws, $v1, ['status' => 'IN_PROGRESS']);
        foreach ([['2026-09-01 08:00:00', '2026-09-01 10:00:00'], ['2026-09-02 08:00:00', '2026-09-02 14:00:00'], ['2026-09-10 08:00:00', null]] as [$req, $iss]) {
            DB::table('work_order_part_requests')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'work_order_id' => $wo->id, 'warehouse_id' => $wh->id,
                'status' => $iss ? 'ISSUED' : 'REQUESTED', 'requested_at' => $req, 'issued_at' => $iss, 'created_at' => now(), 'updated_at' => now()]);
        }
        // Waiting for parts 2026-09-03 10:00 → 2026-09-04 10:00 (24 h) between two work intervals.
        foreach ([['2026-09-03 08:00:00', '2026-09-03 10:00:00', 'WAITING_PART', 'SCHEDULED'], ['2026-09-04 10:00:00', '2026-09-04 12:00:00', 'QC_PENDING', 'WAITING_PART']] as [$from, $to, $end, $start]) {
            DB::table('work_order_work_intervals')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'work_order_id' => $wo->id, 'cycle' => 1,
                'started_at' => $from, 'ended_at' => $to, 'start_from_status' => $start, 'end_to_status' => $end, 'created_at' => now(), 'updated_at' => now()]);
        }

        [, $token] = $this->makeTenantUser($t, ['inventory.view', 'work_order.view']);
        $data = $this->widget($token, 'WH-07', ['months' => 3])->assertOk()->json('data.data');
        $this->assertEquals(['requests' => 2, 'median_hours' => 4.0, 'p90_hours' => 5.6], $data['totals']);
        $this->assertSame(1, $data['open']['requests']);
        $this->assertEquals(['episodes' => 1, 'work_orders' => 1, 'hours' => 24.0, 'median_hours' => 24.0], $data['waiting_part']);
        [, $noWo] = $this->makeTenantUser($t, ['inventory.view']);
        $this->assertNull($this->widget($noWo, 'WH-07', ['months' => 3])->json('data.data.waiting_part'));

        // PR created 1 Sep → PO ordered 4 Sep → first posted GR 10 Sep (a later GR does not count).
        $partner = $this->makePartner($t);
        $pr = (string) Str::uuid();
        DB::table('purchase_requests')->insert(['id' => $pr, 'tenant_id' => $t->id, 'pr_number' => 'PR-1', 'warehouse_id' => $wh->id, 'status' => 'APPROVED',
            'created_at' => '2026-09-01 03:00:00', 'updated_at' => now()]);
        $po = (string) Str::uuid();
        DB::table('purchase_orders')->insert(['id' => $po, 'tenant_id' => $t->id, 'po_number' => 'PO-1', 'partner_id' => $partner->id, 'purchase_request_id' => $pr,
            'delivery_warehouse_id' => $wh->id, 'status' => 'RECEIVED', 'order_date' => '2026-09-04', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['GR-1', '2026-09-10 03:00:00'], ['GR-2', '2026-09-15 03:00:00']] as [$no, $at]) {
            DB::table('goods_receipts')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'gr_number' => $no, 'purchase_order_id' => $po,
                'warehouse_id' => $wh->id, 'partner_id' => $partner->id, 'status' => 'POSTED', 'received_at' => $at, 'created_at' => now(), 'updated_at' => now()]);
        }
        [, $buyer] = $this->makeTenantUser($t, ['purchase_order.view']);
        $cycle = $this->widget($buyer, 'PR-05', ['months' => 3])->assertOk()->json('data.data.totals');
        $this->assertEquals(['orders' => 1, 'with_pr' => 1, 'pr_to_po' => 3.0, 'po_to_gr' => 6.0, 'pr_to_gr' => 9.0], $cycle);
        $this->assertSame('PO-1', $this->details($buyer, 'PR-05', ['months' => 3])->assertOk()->json('data.data.0.po_number'));
    }
}
