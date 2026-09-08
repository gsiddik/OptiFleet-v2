<?php

namespace Tests\Feature\Intelligence;

use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\Intelligence\Services\FeatureRunService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FeaturePipelineTest extends TestCase
{
    private const FEATURE_DATE = '2026-09-06';

    protected function tearDown(): void
    {
        foreach (['vehicle_daily_features', 'component_daily_features', 'tire_daily_features', 'analytics_etl_runs'] as $collection) {
            DB::connection('mongodb')->getDatabase()->selectCollection($collection)->deleteMany([]);
        }
        parent::tearDown();
    }

    private function featureDoc(string $tenantId, string $vehicleId): ?object
    {
        return DB::connection('mongodb')->getDatabase()->selectCollection('vehicle_daily_features')
            ->findOne(['tenant_id' => $tenantId, 'feature_date' => self::FEATURE_DATE, 'vehicle_id' => $vehicleId]);
    }

    public function test_vehicle_feature_extraction_produces_expected_fields_and_handles_missing_data(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['current_odometer' => 50000]);

        $run = app(FeatureRunService::class)->runDataset($tenant->id, 'vehicle_features', self::FEATURE_DATE, 'test');
        $this->assertSame('COMPLETED', $run->status);

        $doc = $this->featureDoc($tenant->id, $vehicle->id);
        $this->assertNotNull($doc);
        $this->assertSame('v1', $doc->feature_set_version);
        $this->assertSame(0, $doc->breakdown_count_30d);
        // No maintenance schedule exists for this vehicle -> explicit nulls, not fabricated zeros.
        $this->assertNull($doc->days_since_last_maintenance);
        $this->assertNull($doc->km_since_last_maintenance);
        $this->assertNull($doc->mtbf_days);
        $this->assertNull($doc->mttr_hours);
        $this->assertFalse($doc->telematics->available);
        $this->assertNull($doc->telematics->speed);
        $this->assertNotNull($doc->source_data_as_of);
    }

    public function test_feature_generation_is_idempotent_not_duplicated(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $this->makeVehicle($tenant, $branch, $category);

        $runner = app(FeatureRunService::class);
        $runner->runDataset($tenant->id, 'vehicle_features', self::FEATURE_DATE, 'test');
        $runner->runDataset($tenant->id, 'vehicle_features', self::FEATURE_DATE, 'test');

        $count = DB::connection('mongodb')->getDatabase()->selectCollection('vehicle_daily_features')
            ->countDocuments(['tenant_id' => $tenant->id, 'feature_date' => self::FEATURE_DATE]);
        $this->assertSame(1, $count);
    }

    public function test_feature_generation_is_tenant_isolated(): void
    {
        $tenantA = $this->makeTenant();
        $tenantB = $this->makeTenant();
        $branchA = $this->makeBranch($tenantA);
        $branchB = $this->makeBranch($tenantB);
        $category = $this->makeVehicleCategory();
        $vehicleA = $this->makeVehicle($tenantA, $branchA, $category);
        $this->makeVehicle($tenantB, $branchB, $category);

        $runner = app(FeatureRunService::class);
        $runner->runDataset($tenantA->id, 'vehicle_features', self::FEATURE_DATE, 'test');

        $this->assertNotNull($this->featureDoc($tenantA->id, $vehicleA->id));
        $countForB = DB::connection('mongodb')->getDatabase()->selectCollection('vehicle_daily_features')
            ->countDocuments(['tenant_id' => $tenantB->id]);
        $this->assertSame(0, $countForB, 'Running tenant A\'s extraction must never write tenant B documents.');
    }

    /**
     * Release-critical (Section 67): a breakdown reported *after* the
     * feature date's end-of-day boundary must never influence that day's
     * feature row. Without this cutoff a model would be trained on
     * information from the future relative to the point it is supposed
     * to be predicting from.
     */
    public function test_temporal_cutoff_excludes_events_after_the_feature_date(): void
    {
        $tenant = $this->makeTenant();
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);

        // Breakdown the day AFTER the feature date — must not count.
        Breakdown::query()->create([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicle->id,
            'reported_at' => \Carbon\CarbonImmutable::parse(self::FEATURE_DATE)->addDay()->setTime(9, 0), 'severity' => 'MAJOR',
            'description' => 'future breakdown', 'status' => 'REPORTED',
        ]);
        // Breakdown ON the feature date itself — must count.
        Breakdown::query()->create([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicle->id,
            'reported_at' => \Carbon\CarbonImmutable::parse(self::FEATURE_DATE)->setTime(9, 0), 'severity' => 'MAJOR',
            'description' => 'same-day breakdown', 'status' => 'REPORTED',
        ]);

        app(FeatureRunService::class)->runDataset($tenant->id, 'vehicle_features', self::FEATURE_DATE, 'test');

        $doc = $this->featureDoc($tenant->id, $vehicle->id);
        $this->assertSame(1, $doc->breakdown_count_30d, 'Only the same-day breakdown should be counted, not the future one.');
    }

    public function test_eligible_tenants_requires_maintenance_intelligence_entitlement(): void
    {
        $tenantWithout = $this->makeTenant();
        $tenantWith = $this->makeTenant();
        $this->grantModule($tenantWith, 'MAINTENANCE_INTELLIGENCE');

        $eligible = app(FeatureRunService::class)->eligibleTenants()->pluck('id')->all();

        $this->assertContains($tenantWith->id, $eligible);
        $this->assertNotContains($tenantWithout->id, $eligible);
    }
}
