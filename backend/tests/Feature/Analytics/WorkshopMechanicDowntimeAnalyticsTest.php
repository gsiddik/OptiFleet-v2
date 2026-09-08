<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Services\AnalyticsRunService;
use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkshopMechanicDowntimeAnalyticsTest extends TestCase
{
    private const BUSINESS_DATE = '2026-09-06';

    protected function tearDown(): void
    {
        foreach ([
            'daily_downtime_metrics', 'daily_workshop_metrics', 'daily_mechanic_metrics', 'analytics_etl_runs',
        ] as $collection) {
            DB::connection('mongodb')->getDatabase()->selectCollection($collection)->deleteMany([]);
        }
        parent::tearDown();
    }

    private function at(string $time): \Carbon\CarbonImmutable
    {
        return \Carbon\CarbonImmutable::parse(self::BUSINESS_DATE.' '.$time, 'UTC');
    }

    public function test_mttr_averages_only_qualifying_completed_work_orders(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);

        // Qualifying: COMPLETED, started 09:00 -> completed 11:00 = 120 min.
        $wo1 = WorkOrder::query()->create([
            'id' => Str::uuid(), 'wo_number' => 'WO-M-1', 'tenant_id' => $tenant->id,
            'branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'vehicle_id' => $vehicle->id,
            'maintenance_type' => 'CORRECTIVE', 'status' => 'COMPLETED',
        ]);
        $wo1->forceFill(['created_at' => $this->at('08:00:00'), 'started_at' => $this->at('09:00:00'), 'completed_at' => $this->at('11:00:00')])->save();

        // Not qualifying: CANCELLED, must be excluded from MTTR even though it has timestamps.
        $wo2 = WorkOrder::query()->create([
            'id' => Str::uuid(), 'wo_number' => 'WO-M-2', 'tenant_id' => $tenant->id,
            'branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'vehicle_id' => $vehicle->id,
            'maintenance_type' => 'CORRECTIVE', 'status' => 'CANCELLED',
        ]);
        $wo2->forceFill(['created_at' => $this->at('08:00:00'), 'started_at' => $this->at('09:00:00'), 'completed_at' => $this->at('20:00:00')])->save();

        $run = app(AnalyticsRunService::class)->runDataset($tenant->id, 'downtime_metrics', self::BUSINESS_DATE, 'test');
        $this->assertSame('COMPLETED', $run->status);

        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('daily_downtime_metrics')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => self::BUSINESS_DATE, 'vehicle_id' => $vehicle->id]);

        $this->assertEqualsWithDelta(120.0, $doc->mttr_minutes, 0.1);
        $this->assertSame(1, $doc->mttr_sample_size);
    }

    public function test_mtbf_uses_lookback_window_and_breakdown_count(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);

        Breakdown::query()->create([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'vehicle_id' => $vehicle->id,
            'reported_at' => $this->at('06:00:00'), 'severity' => 'MAJOR', 'description' => 'x', 'status' => 'REPORTED',
        ]);

        $run = app(AnalyticsRunService::class)->runDataset($tenant->id, 'downtime_metrics', self::BUSINESS_DATE, 'test');
        $this->assertSame('COMPLETED', $run->status);

        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('daily_downtime_metrics')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => self::BUSINESS_DATE, 'vehicle_id' => $vehicle->id]);

        $lookbackDays = config('analytics.vehicle_health.lookback_days');
        $this->assertSame(1, $doc->mtbf_breakdown_count);
        $this->assertEqualsWithDelta($lookbackDays * 24, $doc->mtbf_observed_hours, 1);
        $this->assertEqualsWithDelta($lookbackDays * 24, $doc->mtbf_hours, 1);
    }

    public function test_workspace_utilization_clips_reservations_to_the_business_day(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);

        $workspaceId = (string) Str::uuid();
        DB::table('workspaces')->insert([
            'id' => $workspaceId, 'tenant_id' => $tenant->id, 'workshop_id' => $workshop->id,
            'code' => 'BAY-1', 'name' => 'Bay 1', 'workspace_type' => 'GENERAL_SERVICE_BAY', 'status' => 'AVAILABLE',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // Reservation spans across the business-day boundary: only the
        // portion inside [00:00, 24:00) of the business date should count.
        DB::table('workspace_reservations')->insert([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'workspace_id' => $workspaceId,
            'start_at' => $this->at('22:00:00'), 'end_at' => $this->at('23:59:59')->addDay(),
            'status' => 'COMPLETED', 'created_at' => now(), 'updated_at' => now(),
        ]);

        $run = app(AnalyticsRunService::class)->runDataset($tenant->id, 'workshop_metrics', self::BUSINESS_DATE, 'test');
        $this->assertSame('COMPLETED', $run->status);

        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('daily_workshop_metrics')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => self::BUSINESS_DATE, 'workshop_id' => $workshop->id]);

        // 22:00 -> midnight = 120 minutes clipped into this business day.
        $this->assertSame(120, $doc->workspace_utilization->occupied_minutes);
        $this->assertSame(1440, $doc->workspace_utilization->available_minutes);
        $this->assertEqualsWithDelta(8.33, $doc->workspace_utilization->percentage, 0.01);
    }

    public function test_blocked_workspace_is_excluded_from_available_capacity(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);

        DB::table('workspaces')->insert([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'workshop_id' => $workshop->id,
            'code' => 'BAY-2', 'name' => 'Bay 2', 'workspace_type' => 'GENERAL_SERVICE_BAY', 'status' => 'BLOCKED',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $run = app(AnalyticsRunService::class)->runDataset($tenant->id, 'workshop_metrics', self::BUSINESS_DATE, 'test');
        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('daily_workshop_metrics')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => self::BUSINESS_DATE, 'workshop_id' => $workshop->id]);

        $this->assertSame(0, $doc->workspace_utilization->available_minutes);
    }

    public function test_mechanic_metrics_computes_labor_time_and_utilization(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $worker = $this->makeWorker($tenant, $branch, $workshop);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);

        $wo = WorkOrder::query()->create([
            'id' => Str::uuid(), 'wo_number' => 'WO-MC-1', 'tenant_id' => $tenant->id,
            'branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'vehicle_id' => $vehicle->id,
            'maintenance_type' => 'CORRECTIVE', 'status' => 'COMPLETED',
        ]);
        $jobId = (string) Str::uuid();
        DB::table('maintenance_jobs')->insert([
            'id' => $jobId, 'work_order_id' => $wo->id, 'description' => 'Brake service',
            'status' => 'COMPLETED', 'assigned_mechanic' => $worker->id,
            'completed_at' => $this->at('12:00:00'), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('work_order_labor_logs')->insert([
            'id' => Str::uuid(), 'maintenance_job_id' => $jobId, 'worker_id' => $worker->id,
            'status' => 'FINISHED', 'started_at' => $this->at('10:00:00'), 'completed_at' => $this->at('12:00:00'),
            'actual_minutes' => 120, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('work_order_mechanic_assignments')->insert([
            'id' => Str::uuid(), 'work_order_id' => $wo->id, 'maintenance_job_id' => $jobId,
            'worker_id' => $worker->id, 'role' => 'PRIMARY', 'assigned_at' => $this->at('09:00:00'),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $run = app(AnalyticsRunService::class)->runDataset($tenant->id, 'mechanic_metrics', self::BUSINESS_DATE, 'test');
        $this->assertSame('COMPLETED', $run->status);

        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('daily_mechanic_metrics')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => self::BUSINESS_DATE, 'mechanic_id' => $worker->id]);

        $this->assertSame(1, $doc->assigned_jobs);
        $this->assertSame(1, $doc->completed_jobs);
        $this->assertSame(120, $doc->actual_labor_minutes);
        $this->assertEqualsWithDelta(120.0, $doc->avg_job_duration_minutes, 0.1);
        $shiftMinutes = config('analytics.mechanic_standard_shift_minutes');
        $this->assertEqualsWithDelta((120 / $shiftMinutes) * 100, $doc->utilization->percentage, 0.01);
    }
}
