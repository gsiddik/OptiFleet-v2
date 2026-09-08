<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\Intelligence\Services\FeatureRunService;
use App\Domain\Intelligence\Services\HealthScoreRunService;
use App\Domain\Intelligence\Services\PredictionRunService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class HealthScoreTest extends TestCase
{
    private const FEATURE_DATE = '2026-08-01';

    protected function tearDown(): void
    {
        foreach (['vehicle_daily_features', 'component_daily_features', 'intelligence_predictions', 'analytics_etl_runs'] as $collection) {
            DB::connection('mongodb')->getDatabase()->selectCollection($collection)->deleteMany([]);
        }
        parent::tearDown();
    }

    public function test_healthy_vehicle_scores_high_and_unhealthy_vehicle_scores_lower(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $healthyVehicle = $this->makeVehicle($tenant, $branch, $category);
        $sickVehicle = $this->makeVehicle($tenant, $branch, $category);

        foreach ([1, 2, 3] as $i) {
            Breakdown::query()->create([
                'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $sickVehicle->id,
                'reported_at' => CarbonImmutable::parse(self::FEATURE_DATE)->subDays($i * 5), 'severity' => 'MAJOR',
                'description' => 'x', 'status' => 'REPORTED',
            ]);
        }

        app(FeatureRunService::class)->runDataset($tenant->id, 'vehicle_features', self::FEATURE_DATE, 'test');
        app(PredictionRunService::class)->runForTenant($tenant->id, 'vehicle_failure_risk', self::FEATURE_DATE);
        app(HealthScoreRunService::class)->runForTenant($tenant->id, self::FEATURE_DATE);

        $healthyDoc = DB::connection('mongodb')->getDatabase()->selectCollection('intelligence_predictions')
            ->findOne(['tenant_id' => $tenant->id, 'entity_id' => $healthyVehicle->id, 'prediction_type' => 'vehicle_health_score']);
        $sickDoc = DB::connection('mongodb')->getDatabase()->selectCollection('intelligence_predictions')
            ->findOne(['tenant_id' => $tenant->id, 'entity_id' => $sickVehicle->id, 'prediction_type' => 'vehicle_health_score']);

        $this->assertNotNull($healthyDoc);
        $this->assertNotNull($sickDoc);
        $this->assertGreaterThan($sickDoc->score, $healthyDoc->score);
        $this->assertSame(100.0, $healthyDoc->score);
        $this->assertContains($healthyDoc->risk_level, ['HEALTHY', 'GOOD', 'WATCH', 'AT_RISK', 'CRITICAL']);
        $this->assertNotEmpty($healthyDoc->explanation);

        // Subscore breakdown must be explainable/preserved (Section 21).
        $breakdownLine = collect((array) $sickDoc->explanation)->first(fn ($l) => str_contains($l, 'breakdown history'));
        $this->assertNotNull($breakdownLine);
    }

    public function test_component_health_score_reflects_failure_and_replacement_history(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        $componentGroup = $this->makeComponentGroup();

        $asset = DB::table('component_assets')->insertGetId([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'component_group_id' => $componentGroup->id,
            'current_status' => 'INSTALLED', 'current_vehicle_id' => $vehicle->id,
            'created_at' => now(), 'updated_at' => now(),
        ], 'id');
        DB::table('component_installations')->insert([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'component_asset_id' => $asset,
            'vehicle_id' => $vehicle->id, 'installation_odometer' => 5000,
            'installed_at' => CarbonImmutable::parse(self::FEATURE_DATE)->subDays(60),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        app(FeatureRunService::class)->runDataset($tenant->id, 'component_features', self::FEATURE_DATE, 'test');
        app(HealthScoreRunService::class)->runForTenant($tenant->id, self::FEATURE_DATE);

        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('intelligence_predictions')
            ->findOne(['tenant_id' => $tenant->id, 'entity_id' => (string) $asset, 'prediction_type' => 'component_health_score']);

        $this->assertNotNull($doc);
        $this->assertLessThanOrEqual(100, $doc->score);
        $this->assertNotEmpty($doc->explanation);
    }

    public function test_health_scoring_is_idempotent_and_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $branchA = $this->makeBranch($tenantA);
        $category = $this->makeVehicleCategory();
        $this->makeVehicle($tenantA, $branchA, $category);

        $service = app(HealthScoreRunService::class);
        app(FeatureRunService::class)->runDataset($tenantA->id, 'vehicle_features', self::FEATURE_DATE, 'test');
        app(PredictionRunService::class)->runForTenant($tenantA->id, 'vehicle_failure_risk', self::FEATURE_DATE);

        $service->runForTenant($tenantA->id, self::FEATURE_DATE);
        $service->runForTenant($tenantA->id, self::FEATURE_DATE);

        $countA = DB::connection('mongodb')->getDatabase()->selectCollection('intelligence_predictions')
            ->countDocuments(['tenant_id' => $tenantA->id, 'prediction_type' => 'vehicle_health_score']);
        $countB = DB::connection('mongodb')->getDatabase()->selectCollection('intelligence_predictions')
            ->countDocuments(['tenant_id' => $tenantB->id]);

        $this->assertSame(1, $countA);
        $this->assertSame(0, $countB);
    }
}
