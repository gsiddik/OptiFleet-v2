<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Kpi\KpiRegistry;
use App\Domain\Analytics\Services\AnalyticsRunService;
use App\Domain\WorkOrder\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class KpiCatalogTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ([
            'daily_fleet_snapshots', 'daily_work_order_metrics', 'daily_workshop_metrics', 'analytics_etl_runs',
        ] as $collection) {
            DB::connection('mongodb')->getDatabase()->selectCollection($collection)->deleteMany([]);
        }
        parent::tearDown();
    }

    public function test_fleet_availability_kpi_reads_the_latest_snapshot_in_range(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $category = $this->makeVehicleCategory();
        $this->makeVehicle($tenant, $branch, $category, ['status' => 'ACTIVE']);
        $this->makeVehicle($tenant, $branch, $category, ['status' => 'BREAKDOWN']);

        app(AnalyticsRunService::class)->runDataset($tenant->id, 'fleet_snapshot', '2026-09-06', 'test');

        $kpi = app(KpiRegistry::class)->get('fleet_availability');
        $result = $kpi->calculate($tenant->id, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-06'));

        $this->assertEqualsWithDelta(50.0, $result['value'], 0.01);
        $this->assertSame(1, $result['numerator']);
        $this->assertSame(2, $result['denominator']);
        $this->assertSame('percentage', $kpi->unit);
    }

    public function test_workshop_utilization_kpi_sums_a_nested_dot_path_field_correctly(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);

        $workspaceId = (string) Str::uuid();
        DB::table('workspaces')->insert([
            'id' => $workspaceId, 'tenant_id' => $tenant->id, 'workshop_id' => $workshop->id,
            'code' => 'BAY-K1', 'name' => 'Bay K1', 'workspace_type' => 'GENERAL_SERVICE_BAY', 'status' => 'AVAILABLE',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('workspace_reservations')->insert([
            'id' => Str::uuid(), 'tenant_id' => $tenant->id, 'workspace_id' => $workspaceId,
            'start_at' => CarbonImmutable::parse('2026-09-06 08:00:00'), 'end_at' => CarbonImmutable::parse('2026-09-06 12:00:00'),
            'status' => 'COMPLETED', 'created_at' => now(), 'updated_at' => now(),
        ]);

        app(AnalyticsRunService::class)->runDataset($tenant->id, 'workshop_metrics', '2026-09-06', 'test');

        // Sanity: the underlying document really does hold the nested field.
        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('daily_workshop_metrics')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => '2026-09-06', 'workshop_id' => $workshop->id]);
        $this->assertSame(240, $doc->workspace_utilization->occupied_minutes);

        $kpi = app(KpiRegistry::class)->get('workshop_utilization');
        $result = $kpi->calculate($tenant->id, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-06'), $workshop->id);

        // This is the actual FerretDB/MongoDB dot-path $sum aggregation
        // path — if it silently returned 0 instead of erroring, this
        // assertion (not an exception) is what catches it.
        $this->assertEqualsWithDelta(240.0, $result['numerator'], 0.01);
        $this->assertEqualsWithDelta(1440.0, $result['denominator'], 0.01);
        $this->assertEqualsWithDelta((240 / 1440) * 100, $result['value'], 0.01);
    }

    public function test_rework_rate_kpi_sums_dot_path_fields_across_multiple_days(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);

        foreach (['2026-09-05', '2026-09-06'] as $date) {
            $wo = WorkOrder::query()->create([
                'id' => Str::uuid(), 'wo_number' => 'WO-K-'.$date, 'tenant_id' => $tenant->id,
                'branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'vehicle_id' => $vehicle->id,
                'maintenance_type' => 'CORRECTIVE', 'status' => 'COMPLETED',
            ]);
            $wo->forceFill(['completed_at' => CarbonImmutable::parse($date.' 10:00:00')])->save();
            app(AnalyticsRunService::class)->runDataset($tenant->id, 'work_order_metrics', $date, 'test');
        }

        $kpi = app(KpiRegistry::class)->get('work_order_cycle_time');
        $result = $kpi->calculate($tenant->id, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-06'), $workshop->id);

        // 2 completed WOs total across the range -> weighted-average denominator is 2.
        $this->assertEqualsWithDelta(2.0, $result['denominator'], 0.01);
    }
}
