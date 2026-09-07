<?php

namespace App\Console\Commands;

use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\MaintenanceRequest\Services\MaintenanceRequestService;
use App\Domain\Organization\Models\Branch;
use App\Domain\Organization\Models\Workshop;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\VehicleRelease\Services\VehicleReleaseService;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Services\WorkOrderService;
use App\Domain\WorkOrder\Services\WorkOrderTransitionService;
use App\Domain\Workshop\Models\Workspace;
use App\Domain\Workshop\Services\WorkspaceReservationService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\MasterData\Models\VehicleCategory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-off release-gate concurrency probe for Phase 3 (Section 53 of the
 * brief): forks real OS processes to race the WO numbering sequence, the
 * workspace-reservation overlap check, the maintenance-request-to-Work-
 * Order conversion, the vehicle-release, and a concurrent WO status
 * transition. Not wired into any schedule or route; builds its own
 * throwaway fixtures so it never depends on prior seed-run state, and
 * cleans them up on exit.
 */
class Phase3ConcurrencySmokeTestCommand extends Command
{
    protected $signature = 'concurrency:smoke-test-phase3';
    protected $description = 'Fork concurrent workers to probe Phase 3 (WO/reservation/conversion/release) race safety';

    private ?Tenant $tenant = null;

    public function handle(): int
    {
        [$tenant, $branch, $workshop, $category] = $this->makeFixtures();
        $this->tenant = $tenant;

        $ok = true;
        $ok = $this->woNumberRace($branch, $workshop, $category) && $ok;
        $ok = $this->reservationOverlapRace($workshop) && $ok;
        $ok = $this->mrConversionRace($branch, $workshop, $category) && $ok;
        $ok = $this->doubleReleaseRace($branch, $workshop, $category) && $ok;
        $ok = $this->concurrentTransitionRace($branch, $workshop, $category) && $ok;

        $this->cleanup();

        if (! $ok) {
            $this->error('PHASE 3 CONCURRENCY SMOKE TEST: ONE OR MORE RACES FAILED');
            return self::FAILURE;
        }

        $this->info('PHASE 3 CONCURRENCY SMOKE TEST: ALL RACES SAFE');
        return self::SUCCESS;
    }

    private function fork(int $workers, callable $work): void
    {
        $pids = [];
        for ($i = 0; $i < $workers; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                $this->error('fork failed');
                exit(1);
            }
            if ($pid === 0) {
                DB::purge();
                try {
                    $work($i);
                } catch (\Throwable $e) {
                    // Expected for every loser of a race — the assertions after
                    // fork() check the resulting DB state, not per-worker outcomes.
                }
                exit(0);
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
        }
    }

    private function makeFixtures(): array
    {
        $tenant = Tenant::query()->create([
            'code' => 'SMOKE3-'.Str::upper(Str::random(6)),
            'name' => 'Phase3 Smoke Test Tenant',
            'status' => 'ACTIVE',
        ]);
        $branch = Branch::query()->create([
            'tenant_id' => $tenant->id, 'code' => 'SMK-BR', 'name' => 'Smoke Branch', 'status' => 'ACTIVE',
        ]);
        $workshop = Workshop::query()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'code' => 'SMK-WS',
            'name' => 'Smoke Workshop', 'workshop_type' => 'INTERNAL', 'status' => 'ACTIVE',
        ]);
        $category = VehicleCategory::query()->first() ?? VehicleCategory::query()->create([
            'tenant_id' => null, 'code' => 'SMK-VC', 'name' => 'Smoke Category', 'is_system' => true, 'status' => 'ACTIVE',
        ]);

        return [$tenant, $branch, $workshop, $category];
    }

    private function makeVehicle(Branch $branch, VehicleCategory $category, array $overrides = []): Vehicle
    {
        return Vehicle::query()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'branch_id' => $branch->id,
            'vehicle_category_id' => $category->id,
            'brand' => 'SmokeBrand',
            'model' => 'SmokeModel',
            'registration_number' => 'SMK-'.Str::upper(Str::random(8)),
            'current_odometer' => 10000,
            'status' => 'ACTIVE',
            'operational_status' => 'AVAILABLE',
        ], $overrides));
    }

    private function woNumberRace(Branch $branch, Workshop $workshop, VehicleCategory $category): bool
    {
        $vehicle = $this->makeVehicle($branch, $category);
        $this->info("\n[1/5] Racing 15 workers generating WO numbers for vehicle {$vehicle->id}");

        $this->fork(15, function () use ($vehicle, $workshop) {
            app(WorkOrderService::class)->create($vehicle, [
                'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
            ]);
        });

        $numbers = WorkOrder::query()->where('vehicle_id', $vehicle->id)->pluck('wo_number');
        $distinct = $numbers->unique()->count();

        $this->line("WOs created: {$numbers->count()} (expect 15), distinct wo_numbers: {$distinct} (expect 15)");
        $ok = $numbers->count() === 15 && $distinct === 15;
        $this->line($ok ? 'WO numbering concurrency: SAFE' : 'WO NUMBERING RACE DETECTED');

        return $ok;
    }

    private function reservationOverlapRace(Workshop $workshop): bool
    {
        $workspace = Workspace::query()->create([
            'tenant_id' => $this->tenant->id, 'workshop_id' => $workshop->id,
            'code' => 'SMK-BAY', 'name' => 'Smoke Bay', 'workspace_type' => 'GENERAL_SERVICE_BAY', 'status' => 'AVAILABLE',
        ]);
        $start = now()->addDay();
        $end = $start->copy()->addHours(2);

        $this->info("\n[2/5] Racing 10 workers reserving the SAME fresh workspace/window {$workspace->id}");

        $this->fork(10, function () use ($workspace, $start, $end) {
            app(WorkspaceReservationService::class)->reserve(Workspace::find($workspace->id), $start->copy(), $end->copy());
        });

        $count = DB::table('workspace_reservations')->where('workspace_id', $workspace->id)->count();
        $this->line("reservations created: {$count} (expect 1)");
        $ok = $count === 1;
        $this->line($ok ? 'workspace reservation concurrency: SAFE' : 'RESERVATION OVERLAP RACE DETECTED');

        return $ok;
    }

    private function mrConversionRace(Branch $branch, Workshop $workshop, VehicleCategory $category): bool
    {
        $vehicle = $this->makeVehicle($branch, $category);
        $request = app(MaintenanceRequestService::class)->create($vehicle, [
            'workshop_id' => $workshop->id, 'priority' => 'MEDIUM', 'complaint' => 'Smoke test complaint.',
            'source_type' => 'USER', 'status' => 'APPROVED',
        ]);

        $this->info("\n[3/5] Racing 10 workers converting the SAME approved maintenance request {$request->id}");

        $this->fork(10, function () use ($request) {
            app(WorkOrderService::class)->fromMaintenanceRequest(MaintenanceRequest::find($request->id), []);
        });

        $woCount = WorkOrder::query()->where('maintenance_request_id', $request->id)->count();
        $mrStatus = MaintenanceRequest::find($request->id)->status;
        $this->line("Work Orders created from this request: {$woCount} (expect 1), MR status: {$mrStatus} (expect WORK_ORDER_CREATED)");
        $ok = $woCount === 1 && $mrStatus === 'WORK_ORDER_CREATED';
        $this->line($ok ? 'MR-to-WO conversion concurrency: SAFE' : 'DUPLICATE MR-TO-WO CONVERSION DETECTED');

        return $ok;
    }

    private function doubleReleaseRace(Branch $branch, Workshop $workshop, VehicleCategory $category): bool
    {
        $vehicle = $this->makeVehicle($branch, $category);
        $wo = app(WorkOrderService::class)->create($vehicle, [
            'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ]);
        $transitions = app(WorkOrderTransitionService::class);
        foreach (['SUBMITTED', 'APPROVED', 'ASSIGNED', 'SCHEDULED', 'IN_PROGRESS', 'QC_PENDING', 'COMPLETED'] as $status) {
            $wo = $transitions->transition($wo, $status);
        }

        $this->info("\n[4/5] Racing 10 workers releasing the SAME completed Work Order {$wo->id}");

        $this->fork(10, function () use ($wo) {
            app(VehicleReleaseService::class)->release(WorkOrder::find($wo->id), []);
        });

        $releaseCount = DB::table('vehicle_releases')->where('work_order_id', $wo->id)->count();
        $this->line("vehicle_releases rows: {$releaseCount} (expect 1)");
        $ok = $releaseCount === 1;
        $this->line($ok ? 'vehicle release concurrency: SAFE' : 'DOUBLE VEHICLE RELEASE DETECTED');

        return $ok;
    }

    private function concurrentTransitionRace(Branch $branch, Workshop $workshop, VehicleCategory $category): bool
    {
        $vehicle = $this->makeVehicle($branch, $category);
        $wo = app(WorkOrderService::class)->create($vehicle, [
            'workshop_id' => $workshop->id, 'maintenance_type' => 'CORRECTIVE',
        ]);

        $this->info("\n[5/5] Racing 10 workers submitting the SAME draft Work Order {$wo->id}");

        $this->fork(10, function () use ($wo) {
            app(WorkOrderTransitionService::class)->transition(WorkOrder::find($wo->id), 'SUBMITTED');
        });

        $status = WorkOrder::find($wo->id)->status;
        $this->line("final WO status: {$status} (expect SUBMITTED, not double-applied or corrupted)");
        $ok = $status === 'SUBMITTED';
        $this->line($ok ? 'concurrent WO transition: SAFE' : 'CONCURRENT TRANSITION RACE DETECTED');

        return $ok;
    }

    private function cleanup(): void
    {
        if (! $this->tenant) {
            return;
        }
        DB::table('vehicle_releases')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('work_order_labor_logs')->whereIn('maintenance_job_id', function ($q) {
            $q->select('id')->from('maintenance_jobs')->whereIn('work_order_id', function ($q2) {
                $q2->select('id')->from('work_orders')->where('tenant_id', $this->tenant->id);
            });
        })->delete();
        DB::table('work_order_mechanic_assignments')->whereIn('work_order_id', function ($q) {
            $q->select('id')->from('work_orders')->where('tenant_id', $this->tenant->id);
        })->delete();
        DB::table('workspace_reservations')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('work_orders')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('maintenance_requests')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('workspaces')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('vehicles')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('workshops')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('branches')->where('tenant_id', $this->tenant->id)->delete();
        DB::table('tenants')->where('id', $this->tenant->id)->delete();
        $this->info("\ncleaned up throwaway tenant {$this->tenant->id}");
    }
}
