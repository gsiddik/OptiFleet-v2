<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Intelligence\Models\IntelligenceModel;
use App\Domain\Intelligence\Services\ModelRegistryService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class ModelRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        DB::connection('mongodb')->getDatabase()->selectCollection('intelligence_models')->deleteMany([]);
        parent::tearDown();
    }

    private function draft(ModelRegistryService $registry, string $tenantId, array $overrides = []): IntelligenceModel
    {
        return $registry->createDraft(array_merge([
            'model_code' => 'vehicle_failure_risk',
            'model_type' => 'classification',
            'target' => 'vehicle_failure_risk',
            'entity_type' => 'vehicle',
            'algorithm' => 'logistic_regression',
            'feature_set_version' => 'v1',
            'scope' => IntelligenceModel::SCOPE_TENANT,
            'tenant_id' => $tenantId,
            'created_by' => 'test',
        ], $overrides));
    }

    public function test_versions_increment_and_are_never_overwritten(): void
    {
        $tenant = $this->makeTenant();
        $registry = app(ModelRegistryService::class);

        $v1 = $this->draft($registry, $tenant->id);
        $v2 = $this->draft($registry, $tenant->id);

        $this->assertSame(1, $v1->version);
        $this->assertSame(2, $v2->version);
        $this->assertNotSame((string) $v1->id, (string) $v2->id);

        $count = IntelligenceModel::withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
        $this->assertSame(2, $count);
    }

    public function test_activation_requires_meeting_documented_acceptance_criteria(): void
    {
        $tenant = $this->makeTenant();
        $registry = app(ModelRegistryService::class);

        $model = $this->draft($registry, $tenant->id, ['acceptance_criteria' => ['precision' => 0.5, 'recall' => 0.3]]);
        $registry->recordEvaluation($model, ['precision' => 0.4, 'recall' => 0.35], [], ['coefficients' => []], 100, 20);

        $this->expectException(InvalidArgumentException::class);
        $registry->activate($model->fresh());
    }

    public function test_activating_a_new_version_retires_the_previous_active_one(): void
    {
        $tenant = $this->makeTenant();
        $registry = app(ModelRegistryService::class);

        $v1 = $this->draft($registry, $tenant->id, ['acceptance_criteria' => ['precision' => 0.3]]);
        $registry->recordEvaluation($v1, ['precision' => 0.5], [], ['coefficients' => []], 100, 20);
        $v1 = $registry->activate($v1->fresh());
        $this->assertSame(IntelligenceModel::STATUS_ACTIVE, $v1->status);

        $v2 = $this->draft($registry, $tenant->id, ['acceptance_criteria' => ['precision' => 0.3]]);
        $registry->recordEvaluation($v2, ['precision' => 0.6], [], ['coefficients' => []], 120, 25);
        $v2 = $registry->activate($v2->fresh());

        $this->assertSame(IntelligenceModel::STATUS_ACTIVE, $v2->status);
        $this->assertSame(IntelligenceModel::STATUS_RETIRED, $v1->fresh()->status);

        $active = $registry->active('vehicle_failure_risk', IntelligenceModel::SCOPE_TENANT, $tenant->id);
        $this->assertSame((string) $v2->id, (string) $active->id);

        $activeCount = IntelligenceModel::withoutGlobalScopes()
            ->where('model_code', 'vehicle_failure_risk')->where('tenant_id', $tenant->id)
            ->where('status', IntelligenceModel::STATUS_ACTIVE)->count();
        $this->assertSame(1, $activeCount, 'Exactly one ACTIVE version must exist per model_code/scope/tenant.');
    }

    public function test_a_draft_model_cannot_be_activated_directly(): void
    {
        $tenant = $this->makeTenant();
        $registry = app(ModelRegistryService::class);
        $model = $this->draft($registry, $tenant->id);

        $this->expectException(\RuntimeException::class);
        $registry->activate($model);
    }

    public function test_tenant_scoped_models_are_isolated_per_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $registry = app(ModelRegistryService::class);

        $this->draft($registry, $tenantA->id);
        $this->draft($registry, $tenantB->id);

        // Versioning is independent per tenant, not global.
        $vA2 = $this->draft($registry, $tenantA->id);
        $this->assertSame(2, $vA2->version);

        $countB = IntelligenceModel::withoutGlobalScopes()->where('tenant_id', $tenantB->id)->count();
        $this->assertSame(1, $countB);
    }
}
