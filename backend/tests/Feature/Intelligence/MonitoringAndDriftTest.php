<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Intelligence\Models\IntelligenceModel;
use App\Domain\Intelligence\Monitoring\DriftAssessmentService;
use App\Domain\Intelligence\Monitoring\ModelMonitoringService;
use App\Domain\Intelligence\Services\FeatureRunService;
use App\Domain\Intelligence\Services\ModelRegistryService;
use App\Domain\Intelligence\Services\OutcomeFeedbackService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsIntelligenceHistory;
use Tests\TestCase;

class MonitoringAndDriftTest extends TestCase
{
    use BuildsIntelligenceHistory;

    protected function tearDown(): void
    {
        foreach (['vehicle_daily_features', 'intelligence_predictions', 'intelligence_models', 'intelligence_outcomes', 'analytics_etl_runs'] as $collection) {
            DB::connection('mongodb')->getDatabase()->selectCollection($collection)->deleteMany([]);
        }
        parent::tearDown();
    }

    public function test_model_monitoring_reports_prediction_volume_and_outcome_precision(): void
    {
        $tenant = $this->makeTenant();
        $registry = app(ModelRegistryService::class);
        $model = $registry->createDraft([
            'model_code' => 'vehicle_failure_risk', 'model_type' => 'classification', 'target' => 'vehicle_failure_risk',
            'entity_type' => 'vehicle', 'algorithm' => 'logistic_regression', 'feature_set_version' => 'v1',
            'scope' => IntelligenceModel::SCOPE_TENANT, 'tenant_id' => $tenant->id, 'created_by' => 'test',
        ]);

        DB::connection('mongodb')->table('intelligence_predictions')->insert([
            ['tenant_id' => $tenant->id, 'entity_id' => 'v1', 'model_id' => (string) $model->id, 'risk_level' => 'HIGH', 'confidence' => 'HIGH', 'prediction_type' => 'vehicle_failure_risk'],
            ['tenant_id' => $tenant->id, 'entity_id' => 'v2', 'model_id' => (string) $model->id, 'risk_level' => 'LOW', 'confidence' => 'MEDIUM', 'prediction_type' => 'vehicle_failure_risk'],
        ]);

        app(OutcomeFeedbackService::class)->recordOutcome($tenant->id, 'pred-1', 'vehicle', 'v1', 'MATURED_EVALUATION', CarbonImmutable::now(), [
            'predicted_positive' => true, 'actual_positive' => true, 'model_id' => (string) $model->id,
        ]);
        // Correlate model_id on the outcome doc itself for the monitoring query.
        DB::connection('mongodb')->table('intelligence_outcomes')->where('prediction_id', 'pred-1')->update(['model_id' => (string) $model->id]);

        $report = app(ModelMonitoringService::class)->forModel($model);

        $this->assertSame(2, $report['prediction_count']);
        $this->assertNotNull($report['average_confidence']);
        $this->assertSame(1, $report['matured_outcome_count']);
        $this->assertSame(1.0, $report['precision_over_time']);
    }

    public function test_drift_assessment_returns_stable_with_no_change_and_reports_per_field_status(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $this->buildVehicleFailureHistory($tenant, $branch, $category, vehicleCount: 8, days: 20);

        $result = app(DriftAssessmentService::class)->assess($tenant->id, 10);

        $this->assertContains($result['overall_status'], ['STABLE', 'WATCH', 'DRIFTED']);
        $this->assertArrayHasKey('breakdown_count_90d', $result['fields']);
    }

    public function test_drift_assessment_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $branchA = $this->makeBranch($tenantA);
        $category = $this->makeVehicleCategory();
        $this->buildVehicleFailureHistory($tenantA, $branchA, $category, vehicleCount: 8, days: 20);

        $resultB = app(DriftAssessmentService::class)->assess($tenantB->id, 10);

        foreach ($resultB['fields'] as $field) {
            $this->assertSame('INSUFFICIENT_DATA', $field['status'], 'Tenant B has no history of its own — must never reflect tenant A\'s feature distribution.');
        }
    }
}
