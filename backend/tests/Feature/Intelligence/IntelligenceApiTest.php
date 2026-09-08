<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Intelligence\Models\IntelligenceRecommendation;
use App\Domain\Intelligence\Services\FeatureRunService;
use App\Domain\Intelligence\Services\HealthScoreRunService;
use App\Domain\Intelligence\Services\PredictionRunService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IntelligenceApiTest extends TestCase
{
    private const FEATURE_DATE = '2026-08-01';

    protected function tearDown(): void
    {
        foreach (['vehicle_daily_features', 'intelligence_predictions', 'intelligence_recommendations', 'analytics_etl_runs'] as $collection) {
            DB::connection('mongodb')->getDatabase()->selectCollection($collection)->deleteMany([]);
        }
        parent::tearDown();
    }

    private function setUpTenantWithHealthData(): array
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'MAINTENANCE_INTELLIGENCE');
        $branchA = $this->makeBranch($tenant, ['code' => 'BR-A']);
        $branchB = $this->makeBranch($tenant, ['code' => 'BR-B']);
        $category = $this->makeVehicleCategory();
        $vehicleA = $this->makeVehicle($tenant, $branchA, $category);
        $vehicleB = $this->makeVehicle($tenant, $branchB, $category);

        app(FeatureRunService::class)->runDataset($tenant->id, 'vehicle_features', self::FEATURE_DATE, 'test');
        app(PredictionRunService::class)->runForTenant($tenant->id, 'vehicle_failure_risk', self::FEATURE_DATE);
        app(HealthScoreRunService::class)->runForTenant($tenant->id, self::FEATURE_DATE);

        return [$tenant, $branchA, $branchB, $vehicleA, $vehicleB];
    }

    public function test_overview_endpoint_returns_counts_and_freshness(): void
    {
        [$tenant] = $this->setUpTenantWithHealthData();
        [, $token] = $this->makeTenantUser($tenant, ['intelligence.overview.view']);

        $response = $this->getJson('/api/v1/app/intelligence/overview', $this->authHeaders($token));

        $response->assertOk();
        $this->assertArrayHasKey('vehicles_healthy', $response->json('data'));
        $this->assertArrayHasKey('freshness', $response->json('data'));
    }

    public function test_vehicle_list_denied_without_permission(): void
    {
        [$tenant] = $this->setUpTenantWithHealthData();
        [, $token] = $this->makeTenantUser($tenant, []);

        $this->getJson('/api/v1/app/intelligence/vehicles', $this->authHeaders($token))->assertStatus(403);
    }

    public function test_vehicle_list_denied_without_module_entitlement(): void
    {
        $tenant = $this->makeTenant();
        // Deliberately do NOT grant MAINTENANCE_INTELLIGENCE.
        [, $token] = $this->makeTenantUser($tenant, ['intelligence.vehicle.view']);

        $this->getJson('/api/v1/app/intelligence/vehicles', $this->authHeaders($token))->assertStatus(403);
    }

    public function test_branch_scoped_user_cannot_view_vehicle_outside_their_branch(): void
    {
        [$tenant, $branchA, , $vehicleA, $vehicleB] = $this->setUpTenantWithHealthData();
        [, $token] = $this->makeTenantUser($tenant, ['intelligence.vehicle.view'], ['BRANCH' => $branchA->id]);

        $this->getJson("/api/v1/app/intelligence/vehicles/{$vehicleA->id}", $this->authHeaders($token))->assertOk();
        $this->getJson("/api/v1/app/intelligence/vehicles/{$vehicleB->id}", $this->authHeaders($token))->assertStatus(403);
    }

    public function test_overview_high_risk_component_and_rul_counts_respect_branch_scope(): void
    {
        [$tenant, $branchA, , $vehicleA, $vehicleB] = $this->setUpTenantWithHealthData();

        // component_health_score/tire_rul for the OUT-OF-SCOPE vehicle (branch B).
        DB::connection('mongodb')->table('intelligence_predictions')->insert([
            'tenant_id' => $tenant->id, 'entity_type' => 'component', 'entity_id' => 'comp-b',
            'vehicle_id' => $vehicleB->id, 'prediction_type' => 'component_health_score',
            'risk_level' => 'CRITICAL', 'business_date' => self::FEATURE_DATE,
        ]);
        DB::connection('mongodb')->table('intelligence_predictions')->insert([
            'tenant_id' => $tenant->id, 'entity_type' => 'tire', 'entity_id' => 'tire-b',
            'vehicle_id' => $vehicleB->id, 'prediction_type' => 'tire_rul',
            'risk_level' => 'CRITICAL', 'business_date' => self::FEATURE_DATE,
        ]);
        // Same for the IN-SCOPE vehicle (branch A), so a nonzero count is actually expected.
        DB::connection('mongodb')->table('intelligence_predictions')->insert([
            'tenant_id' => $tenant->id, 'entity_type' => 'component', 'entity_id' => 'comp-a',
            'vehicle_id' => $vehicleA->id, 'prediction_type' => 'component_health_score',
            'risk_level' => 'CRITICAL', 'business_date' => self::FEATURE_DATE,
        ]);

        [, $token] = $this->makeTenantUser($tenant, ['intelligence.overview.view'], ['BRANCH' => $branchA->id]);

        $response = $this->getJson('/api/v1/app/intelligence/overview', $this->authHeaders($token));

        $response->assertOk();
        $this->assertSame(1, $response->json('data.high_risk_components'), 'Only branch A\'s high-risk component must be counted.');
        $this->assertSame(0, $response->json('data.low_rul_count'), 'Branch B\'s tire RUL must not leak into a branch-A-scoped count.');
    }

    public function test_cross_tenant_isolation_on_overview(): void
    {
        [$tenantA] = $this->setUpTenantWithHealthData();
        $tenantB = $this->makeTenant();
        $this->grantModule($tenantB, 'MAINTENANCE_INTELLIGENCE');
        [, $tokenB] = $this->makeTenantUser($tenantB, ['intelligence.overview.view']);

        $response = $this->getJson('/api/v1/app/intelligence/overview', $this->authHeaders($tokenB));
        $response->assertOk();
        // Tenant B has no data of its own — must never see tenant A's counts.
        $this->assertSame(0, $response->json('data.vehicles_healthy') + $response->json('data.vehicles_at_risk') + $response->json('data.vehicles_critical'));
    }

    public function test_recommendation_review_accept_and_convert_via_api(): void
    {
        [$tenant, $branch, , $vehicle] = $this->setUpTenantWithHealthData();
        $recommendation = IntelligenceRecommendation::query()->create([
            'tenant_id' => $tenant->id, 'entity_type' => 'vehicle', 'entity_id' => $vehicle->id,
            'insight_level' => 'PRESCRIPTIVE', 'recommendation_type' => 'SCHEDULE_MAINTENANCE', 'priority' => 'HIGH',
            'description' => 'Schedule maintenance', 'status' => IntelligenceRecommendation::STATUS_NEW,
        ]);

        [, $token] = $this->makeTenantUser($tenant, [
            'intelligence.recommendation.view', 'intelligence.recommendation.review',
            'intelligence.recommendation.accept', 'intelligence.recommendation.convert',
        ]);
        $headers = $this->authHeaders($token);

        $this->postJson("/api/v1/app/intelligence/recommendations/{$recommendation->id}/review", [], $headers)->assertOk();
        $this->postJson("/api/v1/app/intelligence/recommendations/{$recommendation->id}/accept", [], $headers)->assertOk();
        $convertResponse = $this->postJson("/api/v1/app/intelligence/recommendations/{$recommendation->id}/convert", [], $headers);

        $convertResponse->assertOk();
        $this->assertSame('INTELLIGENCE', $convertResponse->json('data.maintenance_request.source_type'));
        $this->assertSame(IntelligenceRecommendation::STATUS_CONVERTED, $convertResponse->json('data.recommendation.status'));
    }

    public function test_recommendation_accept_denied_without_specific_permission(): void
    {
        [$tenant, , , $vehicle] = $this->setUpTenantWithHealthData();
        $recommendation = IntelligenceRecommendation::query()->create([
            'tenant_id' => $tenant->id, 'entity_type' => 'vehicle', 'entity_id' => $vehicle->id,
            'insight_level' => 'PRESCRIPTIVE', 'recommendation_type' => 'MONITOR', 'priority' => 'NORMAL',
            'description' => 'x', 'status' => IntelligenceRecommendation::STATUS_NEW,
        ]);

        // Has view but not accept.
        [, $token] = $this->makeTenantUser($tenant, ['intelligence.recommendation.view']);

        $this->postJson("/api/v1/app/intelligence/recommendations/{$recommendation->id}/accept", [], $this->authHeaders($token))->assertStatus(403);
    }
}
