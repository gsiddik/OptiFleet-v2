<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Intelligence\Inventory\InventoryDemandForecastService;
use App\Domain\Intelligence\Inventory\SparePartIntelligenceService;
use App\Domain\Intelligence\Services\FeatureRunService;
use App\Domain\Intelligence\Tire\TireIntelligenceService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class InventoryAndTireIntelligenceTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::connection('mongodb')->getDatabase()->selectCollection('tire_daily_features')->deleteMany([]);
        DB::connection('mongodb')->getDatabase()->selectCollection('analytics_etl_runs')->deleteMany([]);
        parent::tearDown();
    }

    public function test_top_consumed_parts_ranks_by_quantity_and_computes_trend(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        $product = $this->makeProduct($tenant);

        // 2 recent issues (last 45 days), 1 older issue (46-90 days ago) -> positive trend.
        foreach ([-5, -10] as $daysAgo) {
            DB::table('stock_movements')->insert([
                'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
                'movement_type' => 'ISSUE', 'quantity' => -2, 'occurred_at' => CarbonImmutable::now()->subDays(abs($daysAgo)),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        DB::table('stock_movements')->insert([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
            'movement_type' => 'ISSUE', 'quantity' => -1, 'occurred_at' => CarbonImmutable::now()->subDays(70),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $result = app(SparePartIntelligenceService::class)->topConsumedParts($tenant->id, 90);

        $this->assertCount(1, $result);
        $this->assertSame($product->id, $result[0]['product_id']);
        $this->assertSame(5.0, $result[0]['quantity_consumed']);
        $this->assertGreaterThan(0, $result[0]['trend_pct']);
    }

    public function test_demand_forecast_flags_shortage_risk_and_never_touches_procurement(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $warehouse = $this->makeWarehouse($tenant, $branch);
        $product = $this->makeProduct($tenant);

        DB::table('warehouse_stocks')->insert([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
            'quantity_on_hand' => 5, 'reorder_point' => 10, 'created_at' => now(), 'updated_at' => now(),
        ]);
        for ($i = 1; $i <= 30; $i++) {
            DB::table('stock_movements')->insert([
                'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'warehouse_id' => $warehouse->id, 'product_id' => $product->id,
                'movement_type' => 'ISSUE', 'quantity' => -1, 'occurred_at' => CarbonImmutable::now()->subDays($i),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $forecast = app(InventoryDemandForecastService::class)->forecast($tenant->id, $product->id, $warehouse->id, historyDays: 90, horizonDays: 30);

        $this->assertSame('HIGH', $forecast['shortage_risk']);
        $this->assertGreaterThan(0, $forecast['suggested_reorder_quantity']);
        $this->assertSame('MOVING_AVERAGE', $forecast['basis']);
        $this->assertArrayNotHasKey('purchase_order_id', $forecast, 'Forecast must never create/reference a PO directly.');

        $poCountBefore = DB::table('purchase_orders')->count();
        $this->assertSame(0, $poCountBefore, 'Forecasting must never create a Purchase Order — existing Procurement flow stays authoritative.');
    }

    public function test_tire_product_performance_aggregates_the_latest_snapshot(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        $product = $this->makeProduct($tenant);

        $tireId = DB::table('tires')->insertGetId([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'serial_number' => 'T-X', 'product_id' => $product->id,
            'purchase_cost' => 800000, 'current_status' => 'INSTALLED', 'current_vehicle_id' => $vehicle->id,
            'created_at' => now(), 'updated_at' => now(),
        ], 'id');
        DB::table('tire_installations')->insert([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'tire_id' => $tireId, 'vehicle_id' => $vehicle->id,
            'wheel_position' => 'FL', 'installation_odometer' => 1000,
            'installed_at' => now()->subDays(10), 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(FeatureRunService::class)->runDataset($tenant->id, 'tire_features', now()->format('Y-m-d'), 'test');

        $performance = app(TireIntelligenceService::class)->productPerformance($tenant->id);
        $this->assertNotEmpty($performance);
        $this->assertSame($product->id, $performance[0]['product_id']);
        $this->assertSame(1, $performance[0]['tire_count']);
    }
}
