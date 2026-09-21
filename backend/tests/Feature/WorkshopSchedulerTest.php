<?php

namespace Tests\Feature;

use App\Domain\Workshop\Models\Workspace;
use App\Domain\Workshop\Models\WorkspaceReservation;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Next Improvement Tenant Portal - Products" (Scheduler): a Closed Work
 * Order's card must be removed from the Workshop Scheduler, and cards
 * within a day must be ordered by scheduled time (nearest to furthest).
 * The 7-day-window-anchored-on-today default and the status color-coding
 * are frontend-only concerns (WorkshopSchedulerPage.tsx) not exercisable
 * from a backend feature test.
 */
class WorkshopSchedulerTest extends TestCase
{
    private function setUpWorkshop(): array
    {
        $tenant = $this->makeTenant(['code' => 'WSS-'.Str::random(4)]);
        $this->grantModule($tenant, 'VEHICLE');
        $this->grantModule($tenant, 'WORKSHOP');
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);

        return [$tenant, $branch, $workshop];
    }

    public function test_closed_work_order_is_excluded_and_reservations_are_ordered_by_start_time(): void
    {
        [$tenant, $branch, $workshop] = $this->setUpWorkshop();
        $category = $this->makeVehicleCategory();
        $vehicle = $this->makeVehicle($tenant, $branch, $category, ['default_workshop_id' => $workshop->id]);
        $workspace = Workspace::query()->create([
            'tenant_id' => $tenant->id, 'workshop_id' => $workshop->id, 'code' => 'BAY-SCHED', 'name' => 'Scheduler Bay',
            'workspace_type' => 'GENERAL_SERVICE_BAY', 'status' => 'AVAILABLE',
        ]);

        $closedWo = WorkOrder::query()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'vehicle_id' => $vehicle->id,
            'wo_number' => 'WO-CLOSED-1', 'maintenance_type' => 'CORRECTIVE', 'priority' => 'MEDIUM', 'status' => 'CLOSED',
        ]);
        $laterWo = WorkOrder::query()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'vehicle_id' => $vehicle->id,
            'wo_number' => 'WO-LATER', 'maintenance_type' => 'CORRECTIVE', 'priority' => 'MEDIUM', 'status' => 'SCHEDULED',
        ]);
        $earlierWo = WorkOrder::query()->create([
            'tenant_id' => $tenant->id, 'branch_id' => $branch->id, 'workshop_id' => $workshop->id, 'vehicle_id' => $vehicle->id,
            'wo_number' => 'WO-EARLIER', 'maintenance_type' => 'CORRECTIVE', 'priority' => 'MEDIUM', 'status' => 'IN_PROGRESS',
        ]);

        $today = now()->startOfDay();
        WorkspaceReservation::query()->create([
            'tenant_id' => $tenant->id, 'workspace_id' => $workspace->id, 'work_order_id' => $closedWo->id,
            'start_at' => $today->copy()->addHours(8), 'end_at' => $today->copy()->addHours(9), 'status' => 'ACTIVE',
        ]);
        WorkspaceReservation::query()->create([
            'tenant_id' => $tenant->id, 'workspace_id' => $workspace->id, 'work_order_id' => $laterWo->id,
            'start_at' => $today->copy()->addHours(14), 'end_at' => $today->copy()->addHours(15), 'status' => 'RESERVED',
        ]);
        WorkspaceReservation::query()->create([
            'tenant_id' => $tenant->id, 'workspace_id' => $workspace->id, 'work_order_id' => $earlierWo->id,
            'start_at' => $today->copy()->addHours(10), 'end_at' => $today->copy()->addHours(11), 'status' => 'ACTIVE',
        ]);

        [, $token] = $this->makeTenantUser($tenant, ['workspace.view']);
        $response = $this->getJson(
            '/api/v1/app/workshop-scheduler?workshop_id='.$workshop->id.'&from='.$today->toDateString().'&to='.$today->toDateString().' 23:59:59',
            $this->authHeaders($token)
        )->assertOk();

        $reservations = collect($response->json('data'))->firstWhere('id', $workspace->id)['reservations'];
        $woNumbers = collect($reservations)->pluck('work_order.wo_number')->all();

        $this->assertNotContains('WO-CLOSED-1', $woNumbers);
        $this->assertSame(['WO-EARLIER', 'WO-LATER'], $woNumbers);
    }
}
