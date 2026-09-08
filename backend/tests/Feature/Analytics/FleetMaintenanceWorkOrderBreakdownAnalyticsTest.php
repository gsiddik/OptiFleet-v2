<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Services\AnalyticsRunService;
use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class FleetMaintenanceWorkOrderBreakdownAnalyticsTest extends TestCase
{
    private const BUSINESS_DATE = '2026-09-06';

    protected function tearDown(): void
    {
        foreach ([
            'daily_fleet_snapshots', 'daily_vehicle_health', 'daily_maintenance_metrics',
            'daily_work_order_metrics', 'daily_breakdown_metrics', 'analytics_etl_runs',
        ] as $collection) {
            DB::connection('mongodb')->getDatabase()->selectCollection($collection)->deleteMany([]);
        }
        parent::tearDown();
    }

    private function withinBusinessDay(string $time = '10:00:00'): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::parse(self::BUSINESS_DATE.' '.$time, 'UTC');
    }

    public function test_fleet_snapshot_counts_vehicles_by_status_and_computes_availability(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();

        $this->makeVehicle($tenant, $branch, $category, ['status' => 'ACTIVE']);
        $this->makeVehicle($tenant, $branch, $category, ['status' => 'ACTIVE']);
        $this->makeVehicle($tenant, $branch, $category, ['status' => 'BREAKDOWN']);
        $this->makeVehicle($tenant, $branch, $category, ['status' => 'DISPOSED']); // excluded

        $run = app(AnalyticsRunService::class)->runDataset($tenant->id, 'fleet_snapshot', self::BUSINESS_DATE, 'test');
        $this->assertSame('COMPLETED', $run->status);

        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('daily_fleet_snapshots')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => self::BUSINESS_DATE, 'branch_id' => null]);

        $this->assertSame(3, $doc->total_vehicles);
        $this->assertSame(2, $doc->active_vehicles);
        $this->assertSame(1, $doc->breakdown);
        $this->assertEqualsWithDelta(66.67, $doc->availability->percentage, 0.01);
        $this->assertSame(2, $doc->availability->available_count);
        $this->assertSame(3, $doc->availability->planned_count);
    }

    public function test_work_order_metrics_computes_cycle_time_and_overdue(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);

        $created = $this->withinBusinessDay('08:00:00');
        $started = $this->withinBusinessDay('09:00:00');
        $completed = $this->withinBusinessDay('11:30:00'); // 210 min cycle (created->completed), 150 min repair (started->completed)

        // created_at/started_at/completed_at are not mass-assignable
        // (workflow-managed columns) — forceFill+save to backdate them for
        // this fixture, bypassing the guard deliberately.
        $wo1 = WorkOrder::query()->create([
            'id' => Str::uuid(), 'wo_number' => 'WO-TEST-1', 'tenant_id' => $tenant->id,
            'branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'vehicle_id' => $vehicle->id,
            'maintenance_type' => 'CORRECTIVE', 'status' => 'COMPLETED',
        ]);
        $wo1->forceFill(['created_at' => $created, 'started_at' => $started, 'completed_at' => $completed])->save();

        // An overdue, still-open Work Order for the same workshop.
        $wo2 = WorkOrder::query()->create([
            'id' => Str::uuid(), 'wo_number' => 'WO-TEST-2', 'tenant_id' => $tenant->id,
            'branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'vehicle_id' => $vehicle->id,
            'maintenance_type' => 'PREVENTIVE', 'status' => 'IN_PROGRESS',
        ]);
        $wo2->forceFill(['created_at' => $created->subDays(5), 'target_completion_at' => $created->subDay()])->save();

        $run = app(AnalyticsRunService::class)->runDataset($tenant->id, 'work_order_metrics', self::BUSINESS_DATE, 'test');
        $this->assertSame('COMPLETED', $run->status);

        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('daily_work_order_metrics')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => self::BUSINESS_DATE, 'workshop_id' => $workshop->id]);

        $this->assertSame(1, $doc->completed);
        $this->assertEqualsWithDelta(210.0, $doc->avg_cycle_time_minutes, 0.1);
        $this->assertEqualsWithDelta(150.0, $doc->avg_repair_time_minutes, 0.1);
        $this->assertSame(1, $doc->overdue);
        $this->assertSame(1, $doc->open_as_of_date); // only wo2 lacks completed_at; wo1 is COMPLETED
    }

    public function test_breakdown_metrics_counts_by_severity_and_recurring(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicleA = $this->makeVehicle($tenant, $branch, $category);
        $vehicleB = $this->makeVehicle($tenant, $branch, $category);

        $reportedAt = $this->withinBusinessDay('07:00:00');

        Breakdown::query()->create([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicleA->id,
            'reported_at' => $reportedAt, 'severity' => 'MAJOR', 'description' => 'Engine noise', 'status' => 'REPORTED',
        ]);
        Breakdown::query()->create([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicleA->id,
            'reported_at' => $reportedAt->subDays(10), 'severity' => 'MINOR', 'description' => 'Loose bolt', 'status' => 'RESOLVED',
        ]);
        Breakdown::query()->create([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicleB->id,
            'reported_at' => $reportedAt, 'severity' => 'IMMOBILIZED', 'description' => 'Won\'t start', 'status' => 'REPORTED',
        ]);

        $run = app(AnalyticsRunService::class)->runDataset($tenant->id, 'breakdown_metrics', self::BUSINESS_DATE, 'test');
        $this->assertSame('COMPLETED', $run->status);

        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('daily_breakdown_metrics')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => self::BUSINESS_DATE, 'branch_id' => null]);

        $this->assertSame(2, $doc->breakdown_count); // only same-day reports
        $this->assertSame(1, $doc->by_severity->major);
        $this->assertSame(1, $doc->by_severity->immobilized);
        $this->assertSame(1, $doc->immobilized_events);
        // Vehicle A has 2 breakdowns within the 30-day recurring window -> 1 recurring vehicle.
        $this->assertSame(1, $doc->recurring_breakdown_vehicles);
    }

    public function test_vehicle_health_score_deducts_points_for_overdue_maintenance(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        $package = \App\Domain\MaintenancePolicy\Models\MaintenancePackage::query()->create([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id,
            'code' => 'PKG-1', 'name' => 'Standard Service', 'maintenance_type' => 'PREVENTIVE', 'status' => 'ACTIVE',
        ]);
        DB::table('maintenance_schedules')->insert([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id,
            'maintenance_package_id' => $package->id, 'status' => 'OVERDUE',
            'tolerance_days' => 0, 'tolerance_odometer' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $run = app(AnalyticsRunService::class)->runDataset($tenant->id, 'vehicle_health', self::BUSINESS_DATE, 'test');
        $this->assertSame('COMPLETED', $run->status);

        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('daily_vehicle_health')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => self::BUSINESS_DATE, 'vehicle_id' => $vehicle->id]);

        $expectedScore = 100 - config('analytics.vehicle_health.weights.overdue_maintenance');
        $this->assertEqualsWithDelta($expectedScore, $doc->vehicle_health_score, 0.01);
        $this->assertNotSame('CRITICAL', $doc->health_status);
    }

    public function test_maintenance_compliance_reflects_overdue_ratio(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        $package = \App\Domain\MaintenancePolicy\Models\MaintenancePackage::query()->create([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id,
            'code' => 'PKG-2', 'name' => 'Standard Service', 'maintenance_type' => 'PREVENTIVE', 'status' => 'ACTIVE',
        ]);
        $package2 = \App\Domain\MaintenancePolicy\Models\MaintenancePackage::query()->create([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id,
            'code' => 'PKG-3', 'name' => 'Other Service', 'maintenance_type' => 'PREVENTIVE', 'status' => 'ACTIVE',
        ]);
        DB::table('maintenance_schedules')->insert([
            ['id' => Str::uuid(), 'tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'maintenance_package_id' => $package->id, 'status' => 'OVERDUE', 'tolerance_days' => 0, 'tolerance_odometer' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['id' => Str::uuid(), 'tenant_id' => $tenant->id, 'vehicle_id' => $vehicle->id, 'maintenance_package_id' => $package2->id, 'status' => 'UPCOMING', 'tolerance_days' => 0, 'tolerance_odometer' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $run = app(AnalyticsRunService::class)->runDataset($tenant->id, 'maintenance_metrics', self::BUSINESS_DATE, 'test');
        $this->assertSame('COMPLETED', $run->status);

        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('daily_maintenance_metrics')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => self::BUSINESS_DATE, 'branch_id' => null]);

        $this->assertSame(2, $doc->scheduled_maintenance_count);
        $this->assertSame(1, $doc->overdue_maintenance);
        $this->assertEqualsWithDelta(50.0, $doc->maintenance_compliance_percentage, 0.01);
    }
}
