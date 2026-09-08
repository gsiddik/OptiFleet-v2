<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\Intelligence\Models\IntelligenceRecommendation;
use App\Domain\Intelligence\Services\FeatureRunService;
use App\Domain\Intelligence\Services\PredictionRunService;
use App\Domain\Intelligence\Services\RecommendationGenerationService;
use App\Domain\Intelligence\Services\RecommendationReviewService;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class RecommendationTest extends TestCase
{
    private const FEATURE_DATE = '2026-08-01';

    protected function tearDown(): void
    {
        foreach (['vehicle_daily_features', 'intelligence_predictions', 'intelligence_recommendations', 'analytics_etl_runs'] as $collection) {
            DB::connection('mongodb')->getDatabase()->selectCollection($collection)->deleteMany([]);
        }
        parent::tearDown();
    }

    private function makeHighRiskVehicle(\App\Domain\Identity\Models\Tenant $tenant, \App\Domain\Organization\Models\Branch $branch, \App\Domain\MasterData\Models\VehicleCategory $category): \App\Domain\Vehicle\Models\Vehicle
    {
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        foreach ([1, 2, 3, 4] as $i) {
            Breakdown::query()->create([
                'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicle->id,
                'reported_at' => CarbonImmutable::parse(self::FEATURE_DATE)->subDays($i * 3), 'severity' => 'MAJOR',
                'description' => 'x', 'status' => 'REPORTED',
            ]);
        }

        return $vehicle;
    }

    public function test_high_risk_prediction_generates_a_recommendation_with_evidence(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeHighRiskVehicle($tenant, $branch, $category);

        app(FeatureRunService::class)->runDataset($tenant->id, 'vehicle_features', self::FEATURE_DATE, 'test');
        app(PredictionRunService::class)->runForTenant($tenant->id, 'vehicle_failure_risk', self::FEATURE_DATE);
        $created = app(RecommendationGenerationService::class)->generateForTenant($tenant->id, self::FEATURE_DATE);

        $this->assertGreaterThan(0, $created);
        $recommendation = IntelligenceRecommendation::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('entity_id', $vehicle->id)->first();
        $this->assertNotNull($recommendation);
        $this->assertSame('PRESCRIPTIVE', $recommendation->insight_level);
        $this->assertSame(IntelligenceRecommendation::STATUS_NEW, $recommendation->status);
        $this->assertNotEmpty($recommendation->evidence);
        $this->assertNotNull($recommendation->prediction_id);
    }

    public function test_recommendation_generation_is_idempotent_per_prediction(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $this->makeHighRiskVehicle($tenant, $branch, $category);

        app(FeatureRunService::class)->runDataset($tenant->id, 'vehicle_features', self::FEATURE_DATE, 'test');
        app(PredictionRunService::class)->runForTenant($tenant->id, 'vehicle_failure_risk', self::FEATURE_DATE);
        $generator = app(RecommendationGenerationService::class);
        $generator->generateForTenant($tenant->id, self::FEATURE_DATE);
        $second = $generator->generateForTenant($tenant->id, self::FEATURE_DATE);

        $this->assertSame(0, $second, 'Re-running generation for the same predictions must not create duplicates.');
        $count = IntelligenceRecommendation::query()->withoutGlobalScopes()->where('tenant_id', $tenant->id)->count();
        $this->assertSame(1, $count);
    }

    public function test_review_workflow_enforces_valid_transitions_only(): void
    {
        $tenant = $this->makeTenant();
        $recommendation = IntelligenceRecommendation::query()->create([
            'tenant_id' => $tenant->id, 'entity_type' => 'vehicle', 'entity_id' => (string) Str::uuid(),
            'insight_level' => 'PRESCRIPTIVE', 'recommendation_type' => 'MONITOR', 'priority' => 'NORMAL',
            'description' => 'test', 'status' => IntelligenceRecommendation::STATUS_NEW,
        ]);

        $review = app(RecommendationReviewService::class);

        $this->expectException(RuntimeException::class);
        $review->convert($recommendation, (string) Str::uuid()); // cannot convert straight from NEW
    }

    public function test_accepted_recommendation_converts_to_a_linked_maintenance_request(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        $recommendation = IntelligenceRecommendation::query()->create([
            'tenant_id' => $tenant->id, 'entity_type' => 'vehicle', 'entity_id' => $vehicle->id,
            'insight_level' => 'PRESCRIPTIVE', 'recommendation_type' => 'SCHEDULE_MAINTENANCE', 'priority' => 'HIGH',
            'description' => 'Schedule maintenance', 'status' => IntelligenceRecommendation::STATUS_NEW,
        ]);

        $review = app(RecommendationReviewService::class);
        $review->review($recommendation, (string) Str::uuid());
        $accepted = $review->accept($recommendation->fresh(), (string) Str::uuid());
        $this->assertSame(IntelligenceRecommendation::STATUS_ACCEPTED, $accepted->status);

        $request = $review->convert($accepted, (string) Str::uuid());

        $this->assertSame('INTELLIGENCE', $request->source_type);
        $this->assertEquals($recommendation->id, $request->source_recommendation_id);
        $this->assertSame($vehicle->id, $request->vehicle_id);

        $finalRecommendation = $recommendation->fresh();
        $this->assertSame(IntelligenceRecommendation::STATUS_CONVERTED, $finalRecommendation->status);
        $this->assertEquals($request->id, $finalRecommendation->maintenance_request_id);

        $mrCount = MaintenanceRequest::query()->withoutGlobalScopes()->where('vehicle_id', $vehicle->id)->count();
        $this->assertSame(1, $mrCount, 'Conversion must create exactly one Maintenance Request through the existing service.');
    }

    public function test_intelligence_alert_events_are_registered_with_a_default_notification_rule(): void
    {
        // Section 35: reuses the Phase 5 notification engine — a default
        // system rule for the alert events must exist (seeded), not a
        // second/parallel notification mechanism.
        $rule = \App\Domain\Notification\Models\NotificationRule::query()->withoutGlobalScopes()
            ->whereNull('tenant_id')->where('event_code', 'intelligence.vehicle_critical')->where('is_system', true)->first();
        $this->assertNotNull($rule);
        $this->assertTrue($rule->is_active);
    }

    public function test_generating_recommendations_for_a_high_risk_vehicle_does_not_throw_when_dispatching_alerts(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $this->makeHighRiskVehicle($tenant, $branch, $category);

        app(FeatureRunService::class)->runDataset($tenant->id, 'vehicle_features', self::FEATURE_DATE, 'test');
        app(PredictionRunService::class)->runForTenant($tenant->id, 'vehicle_failure_risk', self::FEATURE_DATE);

        // dispatchEvent() never throws by contract (Section 34) even when
        // it can't resolve a recipient — this proves the call path is wired
        // without depending on role/recipient setup unrelated to Phase 7.
        app(RecommendationGenerationService::class)->generateForTenant($tenant->id, self::FEATURE_DATE);
        $this->addToAssertionCount(1);
    }
}
