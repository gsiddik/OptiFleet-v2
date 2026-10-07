<?php

namespace Tests\Feature\Dashboard;

use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Tire\Models\ProductTireSpec;
use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireLoadIndex;
use App\Domain\Tire\Models\TireSpeedRating;
use App\Domain\Tire\Services\TireService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Metric rules of the current-state widgets (FL, MT, WS, WH, PR, TR, FN-05). */
class DashboardCurrentWidgetsTest extends TestCase
{
    use DashboardTestHelpers;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function doc(string $tenantId, string $vehicleId, string $type, array $attributes): void
    {
        DB::table('vehicle_documents')->insert(array_merge([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'vehicle_id' => $vehicleId, 'document_type' => $type,
            'disk' => 'local', 'path' => 'x', 'original_filename' => 'x.pdf', 'mime_type' => 'application/pdf', 'size' => 1,
            'has_expiry' => false, 'needs_extension' => false, 'created_at' => now(), 'updated_at' => now(),
        ], $attributes));
    }

    public function test_fl06_uses_the_latest_document_per_type_and_separates_expiry_from_extension(): void
    {
        Carbon::setTestNow('2026-10-07 03:00:00');
        $tenant = $this->makeTenant(['timezone' => 'Asia/Jakarta']);
        $this->grantModules($tenant, ['VEHICLE']);
        $vehicle = $this->makeVehicle($tenant, $this->makeBranch($tenant), $this->makeVehicleCategory());
        // Old registration expired, but it was renewed: only the renewal (valid 100 days) counts.
        $this->doc($tenant->id, $vehicle->id, 'REGISTRATION', ['issue_date' => '2025-01-01', 'has_expiry' => true, 'expiry_date' => '2026-01-01']);
        $this->doc($tenant->id, $vehicle->id, 'REGISTRATION', ['issue_date' => '2026-01-01', 'has_expiry' => true, 'expiry_date' => '2027-01-15']);
        // Vehicle tax: valid one year, but its extension deadline is in 10 days.
        $this->doc($tenant->id, $vehicle->id, 'VEHICLE_TAX', ['issue_date' => '2026-01-01', 'has_expiry' => true, 'expiry_date' => '2026-12-31',
            'needs_extension' => true, 'extension_deadline' => '2026-10-17']);
        // OTHER documents are assessed one by one.
        $this->doc($tenant->id, $vehicle->id, 'OTHER', ['issue_date' => '2025-01-01', 'has_expiry' => true, 'expiry_date' => '2026-10-01']);
        $this->doc($tenant->id, $vehicle->id, 'OTHER', ['issue_date' => '2026-02-01', 'has_expiry' => true, 'expiry_date' => '2026-11-20']);
        [, $token] = $this->makeTenantUser($tenant, ['vehicle.view']);

        $data = $this->widget($token, 'FL-06')->assertOk()->json('data.data');
        $this->assertSame(['expired' => 1, 'd30' => 0, 'd60' => 1, 'd90' => 1], $data['expiry']);
        $this->assertSame(['expired' => 0, 'd30' => 1, 'd60' => 0, 'd90' => 0], $data['extension']);

        $rows = $this->details($token, 'FL-06', ['measure' => 'extension', 'bucket' => 'd30'])->assertOk()->json('data.data');
        $this->assertSame('VEHICLE_TAX', $rows[0]['document_type']);
        $this->assertSame(10, $rows[0]['days_left']);
    }

    public function test_ws02_ages_open_work_orders_in_tenant_local_days(): void
    {
        // 2026-03-10 18:00 UTC = 2026-03-11 01:00 in Jakarta.
        Carbon::setTestNow('2026-03-10 18:00:00');
        $tenant = $this->makeTenant(['timezone' => 'Asia/Jakarta']);
        $this->grantModules($tenant, ['VEHICLE', 'WORK_ORDER']);
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory());
        // Created 2026-03-07 16:00 UTC = 23:00 local on the 7th → 4 local days old (3 in UTC terms).
        $wo = $this->makeWorkOrder($tenant, $branch, $workshop, $vehicle, ['status' => 'IN_PROGRESS']);
        DB::table('work_orders')->where('id', $wo->id)->update(['created_at' => '2026-03-07 16:00:00']);
        $old = $this->makeWorkOrder($tenant, $branch, $workshop, $vehicle, ['status' => 'ON_HOLD']);
        DB::table('work_orders')->where('id', $old->id)->update(['created_at' => '2026-01-01 00:00:00']);
        $this->makeWorkOrder($tenant, $branch, $workshop, $vehicle, ['status' => 'COMPLETED']);
        $this->makeWorkOrder($tenant, $branch, $workshop, $vehicle, ['status' => 'DRAFT']);
        [, $token] = $this->makeTenantUser($tenant, ['work_order.view']);

        $data = $this->widget($token, 'WS-02')->assertOk()->json('data.data');
        $this->assertSame(['d0_3' => 0, 'd4_7' => 1, 'd8_14' => 0, 'd15_30' => 0, 'd30_plus' => 1], $data['buckets']);
        $this->widget($token, 'WS-01')->assertJsonPath('data.data.total', 2)->assertJsonPath('data.data.draft', 1);
        $rows = $this->details($token, 'WS-02', ['bucket' => 'd4_7'])->json('data.data');
        $this->assertSame(4, $rows[0]['age_days']);
    }

    public function test_mt02_lists_overdue_schedules_most_overdue_first_and_fl03_running_breakdowns(): void
    {
        Carbon::setTestNow('2026-10-07 03:00:00');
        $tenant = $this->makeTenant(['timezone' => 'Asia/Jakarta']);
        $this->grantModules($tenant, ['VEHICLE', 'MAINTENANCE']);
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $a = $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'B 1 A', 'current_odometer' => 52000]);
        $b = $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'B 2 B', 'current_odometer' => 10000]);
        $c = $this->makeVehicle($tenant, $branch, $category, ['registration_number' => 'B 3 C']);
        $package = (string) Str::uuid();
        DB::table('maintenance_packages')->insert(['id' => $package, 'tenant_id' => $tenant->id, 'code' => 'PM-10K', 'name' => 'Service 10K', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([[$a, '2026-09-27', 50000, 'OVERDUE'], [$b, '2026-08-01', null, 'OVERDUE'], [$c, '2026-12-01', null, 'UPCOMING']] as [$v, $due, $km, $status]) {
            DB::table('maintenance_schedules')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'vehicle_id' => $v->id,
                'maintenance_package_id' => $package, 'next_due_date' => $due, 'next_due_odometer' => $km, 'status' => $status, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('breakdowns')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $a->id,
            'description' => 'Engine stall', 'severity' => 'IMMOBILIZED', 'status' => 'VERIFIED', 'reported_at' => '2026-10-06 03:00:00',
            'downtime_start_at' => '2026-10-06 01:00:00', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('breakdowns')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $b->id,
            'description' => 'Fixed', 'severity' => 'MINOR', 'status' => 'RESOLVED', 'reported_at' => '2026-10-01 03:00:00',
            'resolved_at' => '2026-10-02 03:00:00', 'created_at' => now(), 'updated_at' => now()]);
        [, $token] = $this->makeTenantUser($tenant, ['maintenance_schedule.view', 'breakdown.view']);

        $data = $this->widget($token, 'MT-02')->assertOk()->json('data.data');
        $this->assertSame(2, $data['count']);
        $this->assertSame('B 2 B', $data['items'][0]['registration_number']);
        $this->assertSame(67, $data['items'][0]['days_overdue']);
        $this->assertSame('2000', $data['items'][1]['km_over']);
        $this->widget($token, 'MT-01')->assertJsonPath('data.data.by_status.OVERDUE', 2)->assertJsonPath('data.data.by_status.UPCOMING', 1);

        $breakdowns = $this->widget($token, 'FL-03')->assertOk()->json('data.data');
        $this->assertSame(1, $breakdowns['count']);
        $this->assertSame(26, $breakdowns['longest'][0]['running_hours']);
    }

    public function test_wh01_wh02_stock_states_and_needed_by_open_work_orders_and_fn05_value(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModules($tenant, ['VEHICLE', 'INVENTORY', 'WORK_ORDER']);
        $branch = $this->makeBranch($tenant);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory());
        $inventory = app(InventoryService::class);
        [$out, $low, $normal] = [$this->makeProduct($tenant, null, null, ['name' => 'Out filter']), $this->makeProduct($tenant, null, null, ['name' => 'Low belt']), $this->makeProduct($tenant)];
        $inventory->receive($warehouse, $out, 2, '100.50', 'OPENING', null, null, null);
        $inventory->receive($warehouse, $low, 3, '20', 'OPENING', null, null, null);
        $inventory->receive($warehouse, $normal, 50, '1.10', 'OPENING', null, null, null);
        DB::table('warehouse_stocks')->where('product_id', $out->id)->update(['quantity_on_hand' => 0]);
        DB::table('warehouse_stocks')->where('product_id', $low->id)->update(['reorder_point' => 5]);
        $wo = $this->makeWorkOrder($tenant, $branch, $workshop, $vehicle, ['status' => 'WAITING_PART']);
        DB::table('work_order_planned_parts')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'work_order_id' => $wo->id,
            'product_id' => $out->id, 'warehouse_id' => $warehouse->id, 'description' => 'Out filter', 'planned_quantity' => 1, 'issued_quantity' => 0,
            'created_at' => now(), 'updated_at' => now()]);
        [, $token] = $this->makeTenantUser($tenant, ['inventory.view', 'dashboard.finance.view']);

        $this->widget($token, 'WH-01')->assertOk()->assertJsonPath('data.data.by_state', ['OUT' => 1, 'LOW' => 1, 'NORMAL' => 0, 'NOT_SET' => 1]);
        $critical = $this->widget($token, 'WH-02')->assertOk()->json('data.data');
        $this->assertSame(2, $critical['count']);
        $this->assertSame(1, $critical['needed_by_work_orders']);
        $this->assertSame('Out filter', $critical['items'][0]['product_name']);
        // The out-of-stock row has no reorder point: no shortage figure (not 0), threshold reported as not set.
        $this->assertNull($critical['items'][0]['reorder_point']);
        $this->assertNull($critical['items'][0]['shortage']);
        $this->assertTrue($critical['items'][0]['needed_by_work_order']);
        $this->assertSame('2.00', $critical['items'][1]['shortage']);

        // 3 × 20 + 50 × 1.10 = 115.00 (the zeroed row contributes nothing); detail rows reconcile with the total.
        $value = $this->widget($token, 'FN-05')->assertOk()->json('data');
        $this->assertSame('money', $value['unit']);
        $this->assertSame('115.00', $value['data']['total']);
        $rows = $this->details($token, 'FN-05')->json('data.data');
        $this->assertSame('115.00', number_format(array_sum(array_map(fn ($r) => (float) $r['value'], $rows)), 2, '.', ''));
    }

    public function test_wh03_open_in_transit_and_discrepancy_transfers(): void
    {
        Carbon::setTestNow('2026-10-07 03:00:00');
        $tenant = $this->makeTenant();
        $this->grantModules($tenant, ['INVENTORY']);
        $branch = $this->makeBranch($tenant);
        $a = $this->makeWarehouse($tenant, $branch);
        $b = $this->makeWarehouse($tenant, $branch);
        $product = $this->makeProduct($tenant);
        $transfer = fn (string $status, array $extra = []) => tap((string) Str::uuid(), fn ($id) => DB::table('stock_transfers')->insert(array_merge([
            'id' => $id, 'tenant_id' => $tenant->id, 'transfer_number' => 'TS-'.Str::random(5), 'from_warehouse_id' => $a->id, 'to_warehouse_id' => $b->id,
            'status' => $status, 'created_at' => now(), 'updated_at' => now()], $extra)));
        $transfer('REQUESTED');
        $transfer('IN_TRANSIT', ['dispatched_at' => '2026-09-20 00:00:00']);
        $transfer('DISPATCHED', ['dispatched_at' => '2026-10-06 00:00:00']);
        $received = $transfer('RECEIVED', ['dispatched_at' => '2026-10-01 00:00:00', 'received_at' => '2026-10-03 00:00:00']);
        DB::table('stock_transfer_items')->insert(['id' => (string) Str::uuid(), 'stock_transfer_id' => $received, 'product_id' => $product->id,
            'quantity_sent' => 10, 'quantity_received' => 8, 'quantity_lost' => 2, 'created_at' => now(), 'updated_at' => now()]);
        [, $token] = $this->makeTenantUser($tenant, ['stock_transfer.view']);

        $data = $this->widget($token, 'WH-03')->assertOk()->json('data.data');
        $this->assertSame(1, $data['open_total']);
        $this->assertSame(2, $data['in_transit']);
        $this->assertSame(1, $data['in_transit_over_threshold']);
        $this->assertSame(1, $data['received_with_discrepancy']);
        $rows = $this->details($token, 'WH-03', ['view' => 'in_transit'])->json('data.data');
        $this->assertSame(17, $rows[0]['days_in_transit']);
    }

    public function test_tr02_uses_tread_depth_against_d_pull_without_text_search(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModules($tenant, ['VEHICLE', 'TIRE']);
        $branch = $this->makeBranch($tenant);
        $vehicle = $this->makeVehicle($tenant, $branch, $this->makeVehicleCategory());
        $product = $this->makeProduct($tenant, null, null, ['product_type' => 'TIRE']);
        ProductTireSpec::query()->create([
            'product_id' => $product->id, 'vehicle_group' => 'TRUCK_BUS', 'pattern_name' => 'R150', 'width_mm' => 295, 'aspect_ratio_percent' => 80,
            'construction_type' => 'RADIAL', 'rim_diameter_inch' => 22.5, 'tire_type' => 'TUBELESS', 'tire_size_computed' => '295/80 R22.5',
            'single_load_index_id' => TireLoadIndex::query()->firstOrCreate(['code' => '152'], ['max_load_single_kg' => 3550, 'status' => 'ACTIVE'])->id,
            'speed_rating_id' => TireSpeedRating::query()->firstOrCreate(['code' => 'M'], ['max_speed_kmh' => 130, 'status' => 'ACTIVE'])->id,
        ]);
        DB::table('tire_rule_profiles')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Truck', 'tire_category' => 'TRUCK_BUS',
            'd_service_mm' => 3, 'd_pull_mm' => 5, 'a_max_months' => 120, 'a_retread_max_months' => 84, 'n_retread_max' => 2,
            'repair_limits' => '{}', 'application_limits' => '{}', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $install = function (string $serial, string $position, ?string $tread, ?string $recommendation = null) use ($tenant, $product, $vehicle) {
            $tire = Tire::query()->create(['tenant_id' => $tenant->id, 'product_id' => $product->id, 'serial_number' => $serial, 'current_status' => 'IN_STOCK']);
            app(TireService::class)->install($tire, $vehicle, $position, 1000, null, null);
            if ($tread !== null) {
                DB::table('tire_inspections')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'tire_id' => $tire->id,
                    'tread_depth_mm' => $tread, 'recommendation' => $recommendation, 'inspected_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
        };
        $install('T-WORN', 'FL', '4.50');
        $install('T-EDGE', 'FR', '5.00');
        $install('T-GOOD', 'RL', '7.00', 'Replace soon'); // free text is ignored
        $install('T-NONE', 'RR', null);
        [, $token] = $this->makeTenantUser($tenant, ['tire.view']);

        $data = $this->widget($token, 'TR-02')->assertOk()->json('data');
        $this->assertSame(2, $data['data']['installed_due']);
        $this->assertSame(['T-WORN', 'T-EDGE'], array_column($data['data']['installed_items'], 'serial_number'));
        $this->assertSame(1, $data['data']['not_assessable']['no_tread_reading']);
        $this->assertSame('dashboard.limitations.tiresWithoutTreadReading', $data['limitations'][0]['code']);
        $this->widget($token, 'TR-01')->assertJsonPath('data.data.by_group.IN_SERVICE', 4);
    }
}
