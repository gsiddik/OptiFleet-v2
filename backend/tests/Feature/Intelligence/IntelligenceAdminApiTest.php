<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Intelligence\Models\IntelligenceModel;
use App\Domain\Intelligence\Services\ModelRegistryService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntelligenceAdminApiTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::connection('mongodb')->getDatabase()->selectCollection('intelligence_models')->deleteMany([]);
        parent::tearDown();
    }

    private function makeEvaluatedModel(string $tenantId): IntelligenceModel
    {
        $registry = app(ModelRegistryService::class);
        $model = $registry->createDraft([
            'model_code' => 'vehicle_failure_risk', 'model_type' => 'classification', 'target' => 'vehicle_failure_risk',
            'entity_type' => 'vehicle', 'algorithm' => 'logistic_regression', 'feature_set_version' => 'v1',
            'scope' => IntelligenceModel::SCOPE_TENANT, 'tenant_id' => $tenantId, 'created_by' => 'test',
            'acceptance_criteria' => ['precision' => 0.1],
        ]);

        return $registry->recordEvaluation($model, ['precision' => 0.5, 'recall' => 0.5], [], ['algorithm' => 'logistic_regression', 'feature_names' => [], 'coefficients' => [], 'intercept' => 0, 'feature_means' => [], 'feature_stds' => []], 100, 20);
    }

    public function test_model_activation_requires_platform_permission(): void
    {
        $tenant = $this->makeTenant();
        $model = $this->makeEvaluatedModel($tenant->id);
        [, $token] = $this->makePlatformUser([]); // no intelligence.model.activate

        $this->postJson("/api/v1/platform/intelligence/models/{$model->id}/activate", [], $this->authHeaders($token))
            ->assertStatus(403);
    }

    public function test_model_activation_succeeds_with_permission_and_is_audited(): void
    {
        $tenant = $this->makeTenant();
        $model = $this->makeEvaluatedModel($tenant->id);
        [, $token] = $this->makePlatformUser(['intelligence.model.activate']);

        $response = $this->postJson("/api/v1/platform/intelligence/models/{$model->id}/activate", [], $this->authHeaders($token));

        $response->assertOk();
        $this->assertSame(IntelligenceModel::STATUS_ACTIVE, $response->json('data.status'));
        $this->assertDatabaseHas('audit_logs', ['resource_type' => 'IntelligenceModel', 'action' => 'activated']);
    }

    public function test_training_endpoint_requires_permission_and_validates_target(): void
    {
        $tenant = $this->makeTenant();
        [, $token] = $this->makePlatformUser(['intelligence.model.train']);

        $this->postJson('/api/v1/platform/intelligence/training', [
            'tenant_id' => $tenant->id, 'target' => 'not_a_real_target',
        ], $this->authHeaders($token))->assertStatus(422);

        $this->postJson('/api/v1/platform/intelligence/training', [
            'tenant_id' => $tenant->id, 'target' => 'vehicle_failure_risk',
        ], $this->authHeaders($token))->assertStatus(202);
    }

    public function test_monitoring_endpoint_requires_permission(): void
    {
        [, $token] = $this->makePlatformUser([]);

        $this->getJson('/api/v1/platform/intelligence/monitoring', $this->authHeaders($token))->assertStatus(403);
    }
}
