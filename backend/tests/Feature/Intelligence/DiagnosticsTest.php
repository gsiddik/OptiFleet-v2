<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Intelligence\Services\DiagnosticsRunService;
use App\Domain\Intelligence\Services\FeatureRunService;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class DiagnosticsTest extends TestCase
{
    private const FEATURE_DATE = '2026-08-01';

    protected function tearDown(): void
    {
        foreach (['vehicle_daily_features', 'tire_daily_features', 'intelligence_predictions', 'analytics_etl_runs'] as $collection) {
            DB::connection('mongodb')->getDatabase()->selectCollection($collection)->deleteMany([]);
        }
        parent::tearDown();
    }

    private function predictionsFor(string $tenantId, string $entityId, string $type): array
    {
        return DB::connection('mongodb')->getDatabase()->selectCollection('intelligence_predictions')
            ->find(['tenant_id' => $tenantId, 'entity_id' => $entityId, 'prediction_type' => $type])->toArray();
    }

    public function test_vehicle_rul_is_interval_based_and_returns_a_range_not_a_fake_precise_number(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['current_odometer' => 40000]);
        $package = DB::table('maintenance_packages')->insertGetId([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'code' => 'PKG1', 'name' => 'Service',
            'maintenance_type' => 'PREVENTIVE', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ], 'id');
        // created_at must be at/before the feature date's cutoff — a
        // schedule row created "in the future" relative to the business
        // date being extracted must not be visible to that extraction
        // (the same temporal-cutoff discipline VehicleFeatureExtractor
        // enforces on every other query).
        DB::table('maintenance_schedules')->insert([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'maintenance_package_id' => $package,
            'next_due_odometer' => 45000, 'next_due_date' => CarbonImmutable::parse(self::FEATURE_DATE)->addDays(20)->toDateString(),
            'status' => 'UPCOMING',
            'created_at' => CarbonImmutable::parse(self::FEATURE_DATE)->subDay(), 'updated_at' => CarbonImmutable::parse(self::FEATURE_DATE)->subDay(),
        ]);

        app(FeatureRunService::class)->runDataset($tenant->id, 'vehicle_features', self::FEATURE_DATE, 'test');
        app(DiagnosticsRunService::class)->runForTenant($tenant->id, self::FEATURE_DATE);

        $docs = $this->predictionsFor($tenant->id, $vehicle->id, 'vehicle_rul');
        $this->assertCount(1, $docs);
        $doc = $docs[0];
        $this->assertSame('INTERVAL_BASED', $doc['rul']['basis']);
        $this->assertArrayHasKey('low', $doc['rul']['remaining_km']);
        $this->assertArrayHasKey('high', $doc['rul']['remaining_km']);
        $this->assertLessThan($doc['rul']['remaining_km']['high'], $doc['rul']['remaining_km']['low']);
        $this->assertSame(5000.0, $doc['rul']['remaining_km']['point']);
    }

    public function test_repeat_failure_is_detected_and_flagged_as_diagnostic_not_predictive(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        $componentGroup = $this->makeComponentGroup();

        foreach ([1, 2, 3] as $i) {
            $mr = MaintenanceRequest::query()->create([
                'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicle->id,
                'component_group_id' => $componentGroup->id, 'source_type' => 'USER', 'priority' => 'MEDIUM',
                'complaint' => 'brake issue', 'status' => 'DRAFT',
                'request_number' => 'MR-TEST-'.$i,
            ]);
            $mr->forceFill(['created_at' => CarbonImmutable::parse(self::FEATURE_DATE)->subDays($i * 10)])->save();
        }

        app(FeatureRunService::class)->runDataset($tenant->id, 'vehicle_features', self::FEATURE_DATE, 'test');
        app(DiagnosticsRunService::class)->runForTenant($tenant->id, self::FEATURE_DATE);

        $entityId = $vehicle->id.':'.$componentGroup->id;
        $docs = $this->predictionsFor($tenant->id, $entityId, 'repeat_failure');
        $this->assertCount(1, $docs);
        $this->assertSame('DIAGNOSTIC', $docs[0]['insight_level']);
        $this->assertSame(3, $docs[0]['occurrences']);
        $this->assertStringContainsString('3 repairs', $docs[0]['explanation'][0]);
    }

    public function test_diagnostics_run_is_idempotent_and_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $branchA = $this->makeBranch($tenantA);
        $category = $this->makeVehicleCategory();
        $this->makeVehicle($tenantA, $branchA, $category);

        $service = app(DiagnosticsRunService::class);
        app(FeatureRunService::class)->runDataset($tenantA->id, 'vehicle_features', self::FEATURE_DATE, 'test');

        $service->runForTenant($tenantA->id, self::FEATURE_DATE);
        $countBefore = DB::connection('mongodb')->getDatabase()->selectCollection('intelligence_predictions')->countDocuments(['tenant_id' => $tenantA->id]);
        $service->runForTenant($tenantA->id, self::FEATURE_DATE);
        $countAfter = DB::connection('mongodb')->getDatabase()->selectCollection('intelligence_predictions')->countDocuments(['tenant_id' => $tenantA->id]);

        $this->assertSame($countBefore, $countAfter);

        $countB = DB::connection('mongodb')->getDatabase()->selectCollection('intelligence_predictions')->countDocuments(['tenant_id' => $tenantB->id]);
        $this->assertSame(0, $countB);
    }

    public function test_tire_rul_uses_assumed_expected_life_and_is_labeled_interval_based(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        $product = $this->makeProduct($tenant);

        $tireId = DB::table('tires')->insertGetId([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'serial_number' => 'T-1', 'product_id' => $product->id,
            'purchase_cost' => 1000000, 'current_status' => 'INSTALLED', 'current_vehicle_id' => $vehicle->id,
            'created_at' => now(), 'updated_at' => now(),
        ], 'id');
        DB::table('tire_installations')->insert([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'tire_id' => $tireId, 'vehicle_id' => $vehicle->id,
            'wheel_position' => 'FL', 'installation_odometer' => 10000,
            'installed_at' => CarbonImmutable::parse(self::FEATURE_DATE)->subDays(30),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(FeatureRunService::class)->runDataset($tenant->id, 'tire_features', self::FEATURE_DATE, 'test');
        app(DiagnosticsRunService::class)->runForTenant($tenant->id, self::FEATURE_DATE);

        $docs = $this->predictionsFor($tenant->id, (string) $tireId, 'tire_rul');
        $this->assertCount(1, $docs);
        $this->assertSame('INTERVAL_BASED', $docs[0]['rul']['basis']);
    }
}
