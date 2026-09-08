<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Intelligence\Models\IntelligenceModel;
use App\Domain\Intelligence\Services\FeatureRunService;
use App\Domain\Intelligence\Services\ModelRegistryService;
use App\Domain\Intelligence\Services\PredictionRunService;
use App\Domain\Intelligence\Services\PredictionService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PredictionPipelineTest extends TestCase
{
    private const FEATURE_DATE = '2026-08-01';

    protected function tearDown(): void
    {
        foreach (['vehicle_daily_features', 'intelligence_predictions', 'intelligence_models', 'analytics_etl_runs'] as $collection) {
            DB::connection('mongodb')->getDatabase()->selectCollection($collection)->deleteMany([]);
        }
        parent::tearDown();
    }

    private function predictionDoc(string $tenantId, string $vehicleId): ?object
    {
        return DB::connection('mongodb')->getDatabase()->selectCollection('intelligence_predictions')
            ->findOne(['tenant_id' => $tenantId, 'entity_id' => $vehicleId, 'prediction_type' => 'vehicle_failure_risk']);
    }

    public function test_prediction_falls_back_to_rule_based_when_no_active_model_and_is_explained_by_the_features(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        // Deliberately high-risk feature profile: several overdue items + breakdowns.
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        \App\Domain\Breakdown\Models\Breakdown::query()->create([
            'id' => \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicle->id,
            'reported_at' => CarbonImmutable::parse(self::FEATURE_DATE)->subDays(5), 'severity' => 'MAJOR', 'description' => 'x', 'status' => 'REPORTED',
        ]);
        \App\Domain\Breakdown\Models\Breakdown::query()->create([
            'id' => \Illuminate\Support\Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicle->id,
            'reported_at' => CarbonImmutable::parse(self::FEATURE_DATE)->subDays(2), 'severity' => 'MAJOR', 'description' => 'x', 'status' => 'REPORTED',
        ]);

        app(FeatureRunService::class)->runDataset($tenant->id, 'vehicle_features', self::FEATURE_DATE, 'test');
        app(PredictionRunService::class)->runForTenant($tenant->id, 'vehicle_failure_risk', self::FEATURE_DATE);

        $doc = $this->predictionDoc($tenant->id, $vehicle->id);
        $this->assertNotNull($doc);
        $this->assertSame('RULE_BASED', $doc->source);
        $this->assertNull($doc->model_id);
        $this->assertGreaterThan(0, $doc->score);
        $this->assertContains($doc->risk_level, ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL']);
        $this->assertContains($doc->confidence, ['LOW', 'MEDIUM', 'HIGH']);

        // Explanation must be traceable to the actual feature that drove it:
        // breakdown_count_90d=2 should be among the contributing factors with a positive contribution.
        $breakdownFactor = collect((array) $doc->contributing_factors)->first(fn ($f) => $f->factor === 'breakdown_count_90d');
        $this->assertNotNull($breakdownFactor);
        $this->assertEquals(2, $breakdownFactor->count);
        $this->assertGreaterThan(0, $breakdownFactor->contribution);
        $this->assertStringContainsString('2 breakdown', implode(' ', (array) $doc->explanation));
    }

    public function test_prediction_uses_active_model_when_one_exists_and_labels_the_source_as_ml(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        app(FeatureRunService::class)->runDataset($tenant->id, 'vehicle_features', self::FEATURE_DATE, 'test');

        $registry = app(ModelRegistryService::class);
        $model = $registry->createDraft([
            'model_code' => 'vehicle_failure_risk', 'model_type' => 'classification', 'target' => 'vehicle_failure_risk',
            'entity_type' => 'vehicle', 'algorithm' => 'logistic_regression', 'feature_set_version' => 'v1',
            'scope' => IntelligenceModel::SCOPE_TENANT, 'tenant_id' => $tenant->id, 'created_by' => 'test',
            'acceptance_criteria' => ['precision' => 0.0],
        ]);
        $artifact = [
            'algorithm' => 'logistic_regression',
            'feature_names' => ['breakdown_count_90d'],
            'coefficients' => [1.5],
            'intercept' => -2.0,
            'feature_means' => ['breakdown_count_90d' => 0.0],
            'feature_stds' => ['breakdown_count_90d' => 1.0],
        ];
        $registry->recordEvaluation($model, ['precision' => 0.5, 'recall' => 0.5], [], $artifact, 100, 20);
        $registry->activate($model->fresh());

        app(PredictionRunService::class)->runForTenant($tenant->id, 'vehicle_failure_risk', self::FEATURE_DATE);

        $doc = $this->predictionDoc($tenant->id, $vehicle->id);
        $this->assertSame('ML_MODEL', $doc->source);
        $this->assertNotNull($doc->model_id);
        $this->assertNotNull($doc->probability);
    }

    public function test_rerunning_prediction_for_the_same_business_date_is_idempotent(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $this->makeVehicle($tenant, $branch, $category);
        app(FeatureRunService::class)->runDataset($tenant->id, 'vehicle_features', self::FEATURE_DATE, 'test');

        $runner = app(PredictionRunService::class);
        $runner->runForTenant($tenant->id, 'vehicle_failure_risk', self::FEATURE_DATE);
        $runner->runForTenant($tenant->id, 'vehicle_failure_risk', self::FEATURE_DATE);

        $count = DB::connection('mongodb')->getDatabase()->selectCollection('intelligence_predictions')
            ->countDocuments(['tenant_id' => $tenant->id]);
        $this->assertSame(1, $count);
    }

    public function test_prediction_history_is_preserved_across_different_business_dates(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $this->makeVehicle($tenant, $branch, $category);
        $runner = app(PredictionRunService::class);
        $featureRunner = app(FeatureRunService::class);

        foreach (['2026-08-01', '2026-08-02'] as $date) {
            $featureRunner->runDataset($tenant->id, 'vehicle_features', $date, 'test');
            $runner->runForTenant($tenant->id, 'vehicle_failure_risk', $date);
        }

        $count = DB::connection('mongodb')->getDatabase()->selectCollection('intelligence_predictions')
            ->countDocuments(['tenant_id' => $tenant->id]);
        $this->assertSame(2, $count, 'Two distinct business dates must produce two retained prediction records, not an overwrite.');
    }

    public function test_prediction_freshness_reflects_source_data_age(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);

        $service = app(PredictionService::class);
        $doc = $service->predict($tenant->id, 'vehicle', $vehicle->id, 'vehicle_failure_risk', [
            'breakdown_count_90d' => 0, 'overdue_maintenance_count' => 0,
        ], CarbonImmutable::now()->subDays(10));

        $this->assertSame('EXPIRED', $doc['freshness']);
    }
}
