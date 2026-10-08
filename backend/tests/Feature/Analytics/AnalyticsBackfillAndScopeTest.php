<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Contracts\DatasetExtractor;
use App\Domain\Analytics\Services\AnalyticsRunService;
use App\Domain\Analytics\Services\DatasetRegistry;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Domain\WorkOrder\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Phase 6 Section 58 coverage not exercised by the domain-specific test
 * files: the backfill CLI, late-arriving data reprocessing, incremental
 * extraction (a business date only reads its own [start,end) window, not
 * a full historical scan), and workshop/warehouse data-scope filtering
 * on the analytics API (branch scope is covered in AnalyticsApiTest).
 */
class AnalyticsBackfillAndScopeTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ([
            'daily_work_order_metrics', 'daily_workshop_metrics', 'daily_inventory_metrics', 'analytics_etl_runs',
        ] as $collection) {
            DB::connection('mongodb')->getDatabase()->selectCollection($collection)->deleteMany([]);
        }
        parent::tearDown();
    }

    private function registerFakeExtractor(DatasetExtractor $extractor): void
    {
        $registry = new DatasetRegistry;
        $registry->register($extractor);
        $this->app->instance(DatasetRegistry::class, $registry);
    }

    public function test_backfill_command_reprocesses_every_date_in_the_range_synchronously(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $spy = new SpyExtractor;
        $this->registerFakeExtractor($spy);

        $this->artisan('analytics:backfill', [
            '--from' => '2026-09-01',
            '--to' => '2026-09-03',
            '--tenant' => $tenant->id,
            '--dataset' => 'spy_dataset',
            '--sync' => true,
        ])->assertExitCode(0);

        $this->assertEqualsCanonicalizing(
            ['2026-09-01', '2026-09-02', '2026-09-03'],
            $spy::$calledDates,
        );
    }

    public function test_late_arriving_correction_is_reflected_by_reprocessing_the_original_business_date(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);
        $runner = app(AnalyticsRunService::class);

        // Work Order completed on 2026-09-01, first ETL run that day.
        $wo = WorkOrder::query()->create([
            'id' => Str::uuid(), 'wo_number' => 'WO-LATE-1', 'tenant_id' => $tenant->id,
            'branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'vehicle_id' => $vehicle->id,
            'maintenance_type' => 'CORRECTIVE', 'status' => 'COMPLETED',
        ]);
        $wo->forceFill(['completed_at' => CarbonImmutable::parse('2026-09-01 10:00:00')])->save();
        $runner->runDataset($tenant->id, 'work_order_metrics', '2026-09-01', 'schedule');

        $before = DB::connection('mongodb')->getDatabase()->selectCollection('daily_work_order_metrics')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => '2026-09-01', 'workshop_id' => $workshop->id]);
        $this->assertSame(1, $before->completed);

        // Correction entered on 2026-09-03: the WO actually completed a day
        // earlier than first recorded (updated_at moves, completed_at is
        // corrected), simulating a backdated fix.
        $wo->forceFill(['completed_at' => CarbonImmutable::parse('2026-09-01 07:00:00')])->save();

        // A second, identical Work Order is added for the same date after
        // the fact — the scenario Section 11 describes: data for 2026-09-01
        // changes after that day's ETL already ran.
        $wo2 = WorkOrder::query()->create([
            'id' => Str::uuid(), 'wo_number' => 'WO-LATE-2', 'tenant_id' => $tenant->id,
            'branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'vehicle_id' => $vehicle->id,
            'maintenance_type' => 'CORRECTIVE', 'status' => 'COMPLETED',
        ]);
        $wo2->forceFill(['completed_at' => CarbonImmutable::parse('2026-09-01 11:00:00')])->save();

        // Reprocessing (backfill) the ORIGINAL business date picks up the
        // correction — this is what makes late-arriving data safe.
        $this->artisan('analytics:backfill', [
            '--from' => '2026-09-01',
            '--tenant' => $tenant->id,
            '--dataset' => 'work_order_metrics',
            '--sync' => true,
        ])->assertExitCode(0);

        $after = DB::connection('mongodb')->getDatabase()->selectCollection('daily_work_order_metrics')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => '2026-09-01', 'workshop_id' => $workshop->id]);

        $this->assertSame(2, $after->completed);
        // Same document (upserted in place), not a duplicate.
        $this->assertEquals($before->_id, $after->_id);
    }

    public function test_extraction_for_one_business_date_does_not_touch_other_dates_source_rows(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);

        $inWindow = WorkOrder::query()->create([
            'id' => Str::uuid(), 'wo_number' => 'WO-INC-1', 'tenant_id' => $tenant->id,
            'branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'vehicle_id' => $vehicle->id,
            'maintenance_type' => 'CORRECTIVE', 'status' => 'COMPLETED',
        ]);
        $inWindow->forceFill(['completed_at' => CarbonImmutable::parse('2026-09-05 10:00:00')])->save();

        $outsideWindow = WorkOrder::query()->create([
            'id' => Str::uuid(), 'wo_number' => 'WO-INC-2', 'tenant_id' => $tenant->id,
            'branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'vehicle_id' => $vehicle->id,
            'maintenance_type' => 'CORRECTIVE', 'status' => 'COMPLETED',
        ]);
        $outsideWindow->forceFill(['completed_at' => CarbonImmutable::parse('2026-09-06 10:00:00')])->save();

        $run = app(AnalyticsRunService::class)->runDataset($tenant->id, 'work_order_metrics', '2026-09-05', 'test');
        $this->assertSame('COMPLETED', $run->status);

        $doc = DB::connection('mongodb')->getDatabase()->selectCollection('daily_work_order_metrics')
            ->findOne(['tenant_id' => $tenant->id, 'snapshot_date' => '2026-09-05', 'workshop_id' => $workshop->id]);

        // Only the WO completed within 2026-09-05's window is counted —
        // extraction reads that one day's slice, not a full historical scan.
        $this->assertSame(1, $doc->completed);
    }

    public function test_workshop_scoped_user_only_sees_own_workshop_documents(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $workshopA = $this->makeWorkshop($tenant, $branch, ['code' => 'WS-A']);
        $workshopB = $this->makeWorkshop($tenant, $branch, ['code' => 'WS-B']);

        // The endpoints default to the last 30 days, so the documents must be dated relative to today.
        $snapshotDate = CarbonImmutable::now()->subDay()->toDateString();
        DB::connection('mongodb')->getDatabase()->selectCollection('daily_workshop_metrics')->insertMany([
            ['tenant_id' => $tenant->id, 'snapshot_date' => $snapshotDate, 'workshop_id' => $workshopA->id, 'wo_throughput' => 5],
            ['tenant_id' => $tenant->id, 'snapshot_date' => $snapshotDate, 'workshop_id' => $workshopB->id, 'wo_throughput' => 9],
        ]);

        [, $token] = $this->makeTenantUser($tenant, ['analytics.workshop.view'], ['WORKSHOP' => $workshopA->id]);

        $response = $this->getJson('/api/v1/app/analytics/workshops', $this->authHeaders($token))->assertOk();
        $workshopIds = collect($response->json('data.metrics'))->pluck('workshop_id')->unique()->values()->all();
        $this->assertSame([$workshopA->id], $workshopIds);

        $this->getJson("/api/v1/app/analytics/workshops?dimension_value={$workshopB->id}", $this->authHeaders($token))->assertStatus(403);
    }

    public function test_warehouse_scoped_user_only_sees_own_warehouse_documents(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $warehouseA = $this->makeWarehouse($tenant);
        $warehouseB = $this->makeWarehouse($tenant);

        // The endpoints default to the last 30 days, so the documents must be dated relative to today.
        $snapshotDate = CarbonImmutable::now()->subDay()->toDateString();
        DB::connection('mongodb')->getDatabase()->selectCollection('daily_inventory_metrics')->insertMany([
            ['tenant_id' => $tenant->id, 'snapshot_date' => $snapshotDate, 'warehouse_id' => $warehouseA->id, 'inventory_value' => 100],
            ['tenant_id' => $tenant->id, 'snapshot_date' => $snapshotDate, 'warehouse_id' => $warehouseB->id, 'inventory_value' => 200],
        ]);

        [, $token] = $this->makeTenantUser($tenant, ['analytics.inventory.view'], ['WAREHOUSE' => $warehouseA->id]);

        $response = $this->getJson('/api/v1/app/analytics/inventory', $this->authHeaders($token))->assertOk();
        $warehouseIds = collect($response->json('data.metrics'))->pluck('warehouse_id')->unique()->values()->all();
        $this->assertSame([$warehouseA->id], $warehouseIds);

        $this->getJson("/api/v1/app/analytics/inventory?dimension_value={$warehouseB->id}", $this->authHeaders($token))->assertStatus(403);
    }
}

class SpyExtractor implements DatasetExtractor
{
    public static array $calledDates = [];

    public function key(): string
    {
        return 'spy_dataset';
    }

    public function label(): string
    {
        return 'Spy';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function run(string $tenantId, CarbonImmutable $businessDate): EtlDatasetResult
    {
        self::$calledDates[] = $businessDate->format('Y-m-d');

        return new EtlDatasetResult;
    }
}
