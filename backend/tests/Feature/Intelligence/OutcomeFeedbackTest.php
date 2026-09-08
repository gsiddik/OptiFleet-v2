<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\Intelligence\Services\FeatureRunService;
use App\Domain\Intelligence\Services\OutcomeFeedbackService;
use App\Domain\Intelligence\Services\PredictionRunService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OutcomeFeedbackTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach (['vehicle_daily_features', 'intelligence_predictions', 'intelligence_outcomes', 'analytics_etl_runs'] as $collection) {
            DB::connection('mongodb')->getDatabase()->selectCollection($collection)->deleteMany([]);
        }
        parent::tearDown();
    }

    public function test_outcome_is_recorded_once_the_prediction_horizon_has_elapsed(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);

        // Feature date far enough in the past that the 30-day horizon has elapsed.
        $featureDate = CarbonImmutable::now()->subDays(40)->format('Y-m-d');
        app(FeatureRunService::class)->runDataset($tenant->id, 'vehicle_features', $featureDate, 'test');
        app(PredictionRunService::class)->runForTenant($tenant->id, 'vehicle_failure_risk', $featureDate);

        // Actual breakdown occurred within the horizon window.
        Breakdown::query()->create([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicle->id,
            'reported_at' => CarbonImmutable::parse($featureDate)->addDays(10), 'severity' => 'MAJOR',
            'description' => 'x', 'status' => 'REPORTED',
        ]);

        $count = app(OutcomeFeedbackService::class)->evaluateMaturedPredictions($tenant->id, 'vehicle_failure_risk');
        $this->assertSame(1, $count);

        $outcome = DB::connection('mongodb')->getDatabase()->selectCollection('intelligence_outcomes')
            ->findOne(['tenant_id' => $tenant->id, 'entity_id' => $vehicle->id]);
        $this->assertNotNull($outcome);
        $this->assertTrue($outcome->details->actual_positive);

        // Re-running must not create a duplicate outcome record for the same prediction.
        $second = app(OutcomeFeedbackService::class)->evaluateMaturedPredictions($tenant->id, 'vehicle_failure_risk');
        $this->assertSame(0, $second);
    }

    public function test_unelapsed_horizon_prediction_is_not_evaluated_yet(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $this->makeVehicle($tenant, $branch, $category);

        $featureDate = CarbonImmutable::now()->subDays(5)->format('Y-m-d');
        app(FeatureRunService::class)->runDataset($tenant->id, 'vehicle_features', $featureDate, 'test');
        app(PredictionRunService::class)->runForTenant($tenant->id, 'vehicle_failure_risk', $featureDate);

        $count = app(OutcomeFeedbackService::class)->evaluateMaturedPredictions($tenant->id, 'vehicle_failure_risk');
        $this->assertSame(0, $count);
    }
}
