<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Services\AnalyticsRunService;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CostTireComponentWarrantyAnalyticsTest extends TestCase
{
    private const BUSINESS_DATE = '2026-09-06';

    protected function tearDown(): void
    {
        foreach ([
            'daily_cost_metrics', 'daily_tire_metrics', 'daily_component_failure_metrics',
            'daily_warranty_metrics', 'analytics_etl_runs',
        ] as $collection) {
            DB::connection('mongodb')->getDatabase()->selectCollection($collection)->deleteMany([]);
        }
        parent::tearDown();
    }

    private function at(string $time): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::parse(self::BUSINESS_DATE.' '.$time, 'UTC');
    }

    public function test_cost_metrics_sums_parts_cost_snapshot_and_guards_denominators(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['current_odometer' => 0, 'engine_hour' => null]);

        $wo = WorkOrder::query()->create([
            'id' => Str::uuid(), 'wo_number' => 'WO-COST-1', 'tenant_id' => $tenant->id,
            'branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'vehicle_id' => $vehicle->id,
            'maintenance_type' => 'CORRECTIVE', 'status' => 'COMPLETED',
        ]);
        $wo->forceFill(['completed_at' => $this->at('10:00:00')])->save();

        DB::table('work_order_planned_parts')->insert([
            'id' => Str::uuid(), 'work_order_id' => $wo->id, 'description' => 'Brake pad',
            'quantity' => 1, 'total_cost' => 250.50, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $run = app(AnalyticsRunService::class)->runDataset($tenant->id, 'cost_metrics', self::BUSINESS_DATE, 'test');
        $this->assertSame('COMPLETED', $run->status);

        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('daily_cost_metrics')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => self::BUSINESS_DATE, 'branch_id' => $branch->id]);

        $this->assertEqualsWithDelta(250.50, $doc->parts_cost, 0.001);
        $this->assertEqualsWithDelta(250.50, $doc->total_maintenance_cost, 0.001);
        $this->assertNull($doc->labor_cost);
        // odometer is 0 for this vehicle -> cost_per_km must be null, not a divide-by-zero.
        $this->assertNull($doc->cost_per_km);
        $this->assertEqualsWithDelta(250.50, $doc->cost_per_vehicle, 0.001);
    }

    public function test_tire_metrics_computes_mileage_and_cost_per_km(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        $productCategory = $this->makeProductCategory();
        $uom = $this->makeUom();
        $product = $this->makeProduct($tenant, $productCategory, $uom);

        $tireId = (string) Str::uuid();
        DB::table('tires')->insert([
            'id' => $tireId, 'tenant_id' => $tenant->id, 'serial_number' => 'TIRE-1', 'product_id' => $product->id,
            'purchase_cost' => 1000, 'current_status' => 'REMOVED', 'current_vehicle_id' => $vehicle->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $installationId = (string) Str::uuid();
        DB::table('tire_installations')->insert([
            'id' => $installationId, 'tenant_id' => $tenant->id, 'tire_id' => $tireId, 'vehicle_id' => $vehicle->id,
            'wheel_position' => 'FL', 'installed_at' => $this->at('08:00:00')->subDays(100),
            'installation_odometer' => 10000, 'removed_at' => $this->at('09:00:00'),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('tire_removals')->insert([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'tire_id' => $tireId, 'tire_installation_id' => $installationId,
            'removal_odometer' => 30000, 'removal_reason' => 'Worn out', 'disposition' => 'SCRAP',
            'removed_at' => $this->at('09:00:00'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $run = app(AnalyticsRunService::class)->runDataset($tenant->id, 'tire_metrics', self::BUSINESS_DATE, 'test');
        $this->assertSame('COMPLETED', $run->status);

        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('daily_tire_metrics')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => self::BUSINESS_DATE, 'branch_id' => $branch->id]);

        $this->assertEqualsWithDelta(20000.0, $doc->average_mileage, 0.01); // 30000 - 10000
        $this->assertEqualsWithDelta(0.05, $doc->cost_per_km, 0.0001); // 1000 / 20000
        $this->assertSame(1, $doc->replacement_frequency);
    }

    public function test_component_failure_rate_and_repair_vs_replace_ratio(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        $group = $this->makeComponentGroup();
        $productCategory = $this->makeProductCategory();
        $uom = $this->makeUom();
        $product = $this->makeProduct($tenant, $productCategory, $uom);

        $assetId = (string) Str::uuid();
        DB::table('component_assets')->insert([
            'id' => $assetId, 'tenant_id' => $tenant->id, 'product_id' => $product->id, 'component_group_id' => $group->id,
            'current_status' => 'SCRAPPED', 'current_vehicle_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $installationId = (string) Str::uuid();
        DB::table('component_installations')->insert([
            'id' => $installationId, 'tenant_id' => $tenant->id, 'component_asset_id' => $assetId, 'vehicle_id' => $vehicle->id,
            'installation_odometer' => 5000, 'installed_at' => $this->at('08:00:00')->subDays(50),
            'removed_at' => $this->at('09:00:00'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('component_removals')->insert([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'component_asset_id' => $assetId, 'component_installation_id' => $installationId,
            'removal_odometer' => 25000, 'removal_reason' => 'Failed', 'disposition' => 'SCRAP',
            'removed_at' => $this->at('09:00:00'), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $run = app(AnalyticsRunService::class)->runDataset($tenant->id, 'component_failure_metrics', self::BUSINESS_DATE, 'test');
        $this->assertSame('COMPLETED', $run->status);

        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('daily_component_failure_metrics')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => self::BUSINESS_DATE, 'component_group_id' => $group->id]);

        $this->assertSame(1, $doc->failure_count);
        $this->assertEqualsWithDelta(100.0, $doc->failure_rate_percentage, 0.01); // 1 failure / 1 ever-installed asset
        $this->assertEqualsWithDelta(20000.0, $doc->mean_mileage_to_failure, 0.01);
        $this->assertEqualsWithDelta(0.0, $doc->repair_vs_replace_ratio, 0.01); // 0 repairs / 1 scrap
    }

    public function test_warranty_metrics_computes_utilization_and_claim_value(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        $partner = $this->makePartner($tenant);

        $warrantyId = (string) Str::uuid();
        DB::table('warranties')->insert([
            'id' => $warrantyId, 'tenant_id' => $tenant->id, 'coverage_basis' => 'DATE', 'starts_at' => '2026-01-01',
            'partner_id' => $partner->id, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $claimId = (string) Str::uuid();
        DB::table('warranty_claims')->insert([
            'id' => $claimId, 'tenant_id' => $tenant->id, 'claim_number' => 'WC-1', 'warranty_id' => $warrantyId,
            'vehicle_id' => $vehicle->id, 'failure_date' => '2026-09-05', 'claim_amount' => 500,
            'reason' => 'Engine failure', 'status' => 'SETTLED',
            'created_at' => $this->at('08:00:00'), 'settled_at' => $this->at('12:00:00'), 'updated_at' => now(),
        ]);

        $run = app(AnalyticsRunService::class)->runDataset($tenant->id, 'warranty_metrics', self::BUSINESS_DATE, 'test');
        $this->assertSame('COMPLETED', $run->status);

        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('daily_warranty_metrics')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => self::BUSINESS_DATE, 'branch_id' => $branch->id]);

        $this->assertSame(1, $doc->claims_submitted);
        $this->assertSame(1, $doc->settled);
        $this->assertEqualsWithDelta(500.0, $doc->claim_value, 0.01);
        $this->assertEqualsWithDelta(100.0, $doc->warranty_utilization_percentage, 0.01);
        $this->assertSame(1, $doc->failure_within_warranty);
    }
}
