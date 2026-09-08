<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Intelligence\DataReadiness\DataReadinessAssessmentService;
use App\Domain\Intelligence\DataReadiness\DataReadinessResult;
use App\Domain\Intelligence\Services\FeatureRunService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsIntelligenceHistory;
use Tests\TestCase;

class DataReadinessTest extends TestCase
{
    use BuildsIntelligenceHistory;

    protected function tearDown(): void
    {
        DB::connection('mongodb')->getDatabase()->selectCollection('vehicle_daily_features')->deleteMany([]);
        DB::connection('mongodb')->getDatabase()->selectCollection('analytics_etl_runs')->deleteMany([]);
        parent::tearDown();
    }

    public function test_no_data_is_not_ready(): void
    {
        $tenant = $this->makeTenant();

        $result = app(DataReadinessAssessmentService::class)->assess($tenant->id, 'vehicle_failure_risk');

        $this->assertSame(DataReadinessResult::NOT_READY, $result->status);
        $this->assertSame(0, $result->sampleSize);
    }

    public function test_sufficient_history_reaches_ready_or_limited_with_at_least_one_positive(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $this->buildVehicleFailureHistory($tenant, $branch, $category);

        $result = app(DataReadinessAssessmentService::class)->assess($tenant->id, 'vehicle_failure_risk');

        $this->assertContains($result->status, [DataReadinessResult::LIMITED, DataReadinessResult::READY]);
        $this->assertGreaterThan(0, $result->sampleSize);
        $this->assertGreaterThan(0, $result->positiveCount);
    }

    public function test_labels_within_unelapsed_horizon_are_excluded_not_treated_as_negative(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $runner = app(FeatureRunService::class);
        $vehicle = $this->makeVehicle($tenant, $branch, $category);

        // Feature date only 5 days ago: horizon (30 days) has not elapsed,
        // so this row's label must be unknown/excluded, never a hard "no failure".
        $recentDate = CarbonImmutable::now()->subDays(5)->format('Y-m-d');
        $runner->runDataset($tenant->id, 'vehicle_features', $recentDate, 'test');

        $result = app(DataReadinessAssessmentService::class)->assess($tenant->id, 'vehicle_failure_risk');
        $this->assertSame(0, $result->sampleSize, 'A feature row whose horizon has not elapsed must not count toward the training sample.');
    }

    public function test_readiness_is_computed_independently_per_tenant(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $branchA = $this->makeBranch($tenantA);
        $category = $this->makeVehicleCategory();
        $this->buildVehicleFailureHistory($tenantA, $branchA, $category);

        $resultA = app(DataReadinessAssessmentService::class)->assess($tenantA->id, 'vehicle_failure_risk');
        $resultB = app(DataReadinessAssessmentService::class)->assess($tenantB->id, 'vehicle_failure_risk');

        $this->assertGreaterThan(0, $resultA->sampleSize);
        $this->assertSame(0, $resultB->sampleSize, 'Tenant B must not see tenant A\'s training data (Section 11 — no pooling).');
    }
}
