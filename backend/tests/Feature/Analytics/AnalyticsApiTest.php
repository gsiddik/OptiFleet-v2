<?php

namespace Tests\Feature\Analytics;

use App\Domain\Analytics\Services\AnalyticsRunService;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnalyticsApiTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ([
            'daily_fleet_snapshots', 'analytics_etl_runs',
        ] as $collection) {
            DB::connection('mongodb')->getDatabase()->selectCollection($collection)->deleteMany([]);
        }
        parent::tearDown();
    }

    private function setUpTenantWithFleetData(): array
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branchA = $this->makeBranch($tenant, ['code' => 'BR-A']);
        $branchB = $this->makeBranch($tenant, ['code' => 'BR-B']);
        $category = $this->makeVehicleCategory();
        $this->makeVehicle($tenant, $branchA, $category, ['status' => 'ACTIVE']);
        $this->makeVehicle($tenant, $branchB, $category, ['status' => 'BREAKDOWN']);

        app(AnalyticsRunService::class)->runDataset($tenant->id, 'fleet_snapshot', now()->format('Y-m-d'), 'test');

        return [$tenant, $branchA, $branchB];
    }

    public function test_overview_endpoint_returns_kpis_and_freshness(): void
    {
        [$tenant] = $this->setUpTenantWithFleetData();
        [, $token] = $this->makeTenantUser($tenant, ['analytics.overview.view']);

        $response = $this->getJson('/api/v1/app/analytics/overview', $this->authHeaders($token));

        $response->assertOk();
        $this->assertIsArray($response->json('data.kpis'));
        $this->assertArrayHasKey('data_as_of', $response->json('data.freshness'));
        $this->assertArrayHasKey('last_successful_etl_at', $response->json('data.freshness'));
    }

    public function test_fleet_endpoint_denied_without_permission(): void
    {
        [$tenant] = $this->setUpTenantWithFleetData();
        [, $token] = $this->makeTenantUser($tenant, []);

        $this->getJson('/api/v1/app/analytics/fleet', $this->authHeaders($token))->assertStatus(403);
    }

    public function test_fleet_endpoint_denied_without_analytics_module_entitlement(): void
    {
        $tenant = $this->makeTenant();
        // Deliberately do NOT grant ANALYTICS.
        [, $token] = $this->makeTenantUser($tenant, ['analytics.fleet.view']);

        $this->getJson('/api/v1/app/analytics/fleet', $this->authHeaders($token))->assertStatus(403);
    }

    public function test_branch_scoped_user_cannot_request_another_branchs_dimension_value(): void
    {
        [$tenant, $branchA, $branchB] = $this->setUpTenantWithFleetData();
        [, $token] = $this->makeTenantUser($tenant, ['analytics.fleet.view'], ['BRANCH' => $branchA->id]);

        $this->getJson("/api/v1/app/analytics/fleet?dimension_value={$branchA->id}", $this->authHeaders($token))->assertOk();
        $this->getJson("/api/v1/app/analytics/fleet?dimension_value={$branchB->id}", $this->authHeaders($token))->assertStatus(403);
    }

    public function test_branch_scoped_user_without_explicit_filter_only_sees_own_branch_documents(): void
    {
        [$tenant, $branchA] = $this->setUpTenantWithFleetData();
        [, $token] = $this->makeTenantUser($tenant, ['analytics.fleet.view'], ['BRANCH' => $branchA->id]);

        $response = $this->getJson('/api/v1/app/analytics/fleet', $this->authHeaders($token))->assertOk();
        $branchIds = collect($response->json('data.metrics'))->pluck('branch_id')->unique()->values()->all();

        $this->assertSame([$branchA->id], $branchIds);
    }

    public function test_export_streams_csv_with_same_scope_restriction(): void
    {
        [$tenant, $branchA, $branchB] = $this->setUpTenantWithFleetData();
        [, $token] = $this->makeTenantUser($tenant, ['analytics.export'], ['BRANCH' => $branchA->id]);

        $response = $this->get('/api/v1/app/analytics/export/fleet?dimension_value='.$branchA->id, $this->authHeaders($token));
        $response->assertOk();
        $this->assertStringContainsString('text/csv', $response->headers->get('Content-Type'));

        $this->get('/api/v1/app/analytics/export/fleet?dimension_value='.$branchB->id, $this->authHeaders($token))->assertStatus(403);
    }

    public function test_platform_etl_admin_requires_platform_permission_and_audits_actions(): void
    {
        [$tenant] = $this->setUpTenantWithFleetData();
        [, $token] = $this->makePlatformUser(['analytics.etl.run']);

        $response = $this->postJson('/api/v1/platform/analytics/etl/run', ['tenant_id' => $tenant->id, 'dataset' => 'fleet_snapshot'], $this->authHeaders($token));
        $response->assertStatus(202);

        $this->assertDatabaseHas('audit_logs', ['resource_type' => 'AnalyticsEtlRun', 'action' => 'triggered']);
    }

    public function test_platform_etl_runs_listing_requires_view_permission(): void
    {
        [, $token] = $this->makePlatformUser([]);

        $this->getJson('/api/v1/platform/analytics/etl/runs', $this->authHeaders($token))->assertStatus(403);
    }

    public function test_reconciliation_endpoint_detects_matching_counts(): void
    {
        $tenant = $this->makeTenant();
        $this->grantModule($tenant, 'ANALYTICS');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category);

        $wo = \App\Domain\WorkOrder\Models\WorkOrder::query()->create([
            'id' => \Illuminate\Support\Str::uuid(), 'wo_number' => 'WO-REC-1', 'tenant_id' => $tenant->id,
            'branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'vehicle_id' => $vehicle->id,
            'maintenance_type' => 'CORRECTIVE', 'status' => 'COMPLETED',
        ]);
        $date = now()->format('Y-m-d');
        $wo->forceFill(['completed_at' => \Carbon\CarbonImmutable::parse($date.' 10:00:00')])->save();

        app(AnalyticsRunService::class)->runDataset($tenant->id, 'work_order_metrics', $date, 'test');

        [, $token] = $this->makePlatformUser(['analytics.etl.view']);
        $response = $this->getJson("/api/v1/platform/analytics/reconciliation?tenant_id={$tenant->id}&business_date={$date}", $this->authHeaders($token));

        $response->assertOk();
        $this->assertFalse($response->json('data.has_material_mismatch'));

        DB::connection('mongodb')->getDatabase()->selectCollection('daily_work_order_metrics')->deleteMany([]);
    }
}
