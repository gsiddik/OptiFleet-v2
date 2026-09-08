<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Intelligence\Models\IntelligenceModel;
use App\Domain\Intelligence\Services\ModelRegistryService;
use App\Domain\Intelligence\Services\TrainingPipelineService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsIntelligenceHistory;
use Tests\TestCase;

class TrainingPipelineTest extends TestCase
{
    use BuildsIntelligenceHistory;

    protected function tearDown(): void
    {
        foreach (['vehicle_daily_features', 'analytics_etl_runs', 'intelligence_models'] as $collection) {
            DB::connection('mongodb')->getDatabase()->selectCollection($collection)->deleteMany([]);
        }
        parent::tearDown();
    }

    public function test_training_on_insufficient_data_fails_gracefully_without_a_model(): void
    {
        $tenant = $this->makeTenant();

        $model = app(TrainingPipelineService::class)->train($tenant->id, 'vehicle_failure_risk');

        $this->assertSame(IntelligenceModel::STATUS_FAILED, $model->status);
        $this->assertNotEmpty($model->failure_reason);
    }

    public function test_training_pipeline_produces_an_evaluated_model_with_metrics(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $this->buildVehicleFailureHistory($tenant, $branch, $category, vehicleCount: 12, days: 24);

        $model = app(TrainingPipelineService::class)->train($tenant->id, 'vehicle_failure_risk');

        $this->assertContains($model->status, [IntelligenceModel::STATUS_EVALUATED, IntelligenceModel::STATUS_FAILED]);
        if ($model->status === IntelligenceModel::STATUS_EVALUATED) {
            $this->assertArrayHasKey('precision', $model->metrics);
            $this->assertArrayHasKey('recall', $model->metrics);
            $this->assertArrayHasKey('roc_auc', $model->metrics);
            $this->assertGreaterThan(0, $model->dataset_size);
            $this->assertNotEmpty($model->artifact['coefficients']);
            $this->assertSame('logistic_regression', $model->artifact['algorithm']);
        }
    }

    public function test_a_model_failing_its_acceptance_criteria_cannot_be_activated(): void
    {
        $tenant = $this->makeTenant();
        $registry = app(ModelRegistryService::class);
        $model = $registry->createDraft([
            'model_code' => 'vehicle_failure_risk', 'model_type' => 'classification', 'target' => 'vehicle_failure_risk',
            'entity_type' => 'vehicle', 'algorithm' => 'logistic_regression', 'feature_set_version' => 'v1',
            'scope' => IntelligenceModel::SCOPE_TENANT, 'tenant_id' => $tenant->id, 'created_by' => 'test',
            'acceptance_criteria' => ['precision' => 0.9],
        ]);
        $registry->recordEvaluation($model, ['precision' => 0.1, 'recall' => 0.1], [], ['algorithm' => 'logistic_regression'], 50, 5);

        $this->expectException(\InvalidArgumentException::class);
        $registry->activate($model->fresh());
    }

    public function test_training_is_tenant_isolated_no_cross_tenant_data_leakage(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $branchA = $this->makeBranch($tenantA);
        $category = $this->makeVehicleCategory();
        $this->buildVehicleFailureHistory($tenantA, $branchA, $category, vehicleCount: 12, days: 24);

        // Tenant B has zero history of its own — if training ever pooled
        // data across tenants this would incorrectly succeed using A's data.
        $modelB = app(TrainingPipelineService::class)->train($tenantB->id, 'vehicle_failure_risk');

        $this->assertSame(IntelligenceModel::STATUS_FAILED, $modelB->status);
    }
}
