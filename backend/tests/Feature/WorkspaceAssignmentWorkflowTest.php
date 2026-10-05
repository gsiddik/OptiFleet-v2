<?php

namespace Tests\Feature;

use App\Domain\Identity\Models\Tenant;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Services\WorkOrderService;
use App\Domain\Workshop\Models\Workspace;
use App\Domain\Workshop\Models\WorkspaceReservation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Workspace scheduling for Work Orders: Schedule Workspace (DRAFT … ASSIGNED, SCHEDULED, QC_PENDING),
 * approval, the SCHEDULED → IN_PROGRESS guard, Transfer to Another Workspace (history kept, approved
 * immediately), capacity as concurrent assignments, and completion driven by the Work Order.
 */
class WorkspaceAssignmentWorkflowTest extends TestCase
{
    private const WO_PERMISSIONS = [
        'work_order.view', 'work_order.create', 'work_order.update', 'work_order.submit', 'work_order.approve', 'work_order.assign',
        'work_order.schedule', 'work_order.start', 'work_order.pause', 'work_order.complete', 'work_order.close', 'work_order.cancel',
    ];

    private function scenario(array $workspacePermissions = ['workspace.view', 'workspace.reserve', 'workspace.approve']): array
    {
        $tenant = $this->makeTenant(['code' => 'WSA-'.Str::random(4)]);
        foreach (['VEHICLE', 'MAINTENANCE', 'WORKSHOP', 'WORK_ORDER'] as $module) {
            $this->grantModule($tenant, $module);
        }
        $branch = $this->makeBranch($tenant);
        $workshop = $this->makeWorkshop($tenant, $branch);
        $category = $this->makeVehicleCategory();
        [$user, $token] = $this->makeTenantUser($tenant, array_merge(self::WO_PERMISSIONS, $workspacePermissions));

        return [
            'tenant' => $tenant, 'branch' => $branch, 'workshop' => $workshop, 'category' => $category, 'user' => $user,
            'headers' => $this->authHeaders($token),
            'bay' => $this->workspace($tenant, $workshop->id, 'BAY-1', 1),
            'bay2' => $this->workspace($tenant, $workshop->id, 'BAY-2', 2),
            'bay3' => $this->workspace($tenant, $workshop->id, 'BAY-3', 3),
        ];
    }

    private function workspace(Tenant $tenant, string $workshopId, string $code, ?int $capacity, string $status = 'AVAILABLE'): Workspace
    {
        return Workspace::query()->create([
            'tenant_id' => $tenant->id, 'workshop_id' => $workshopId, 'code' => $code, 'name' => "Bay {$code}",
            'workspace_type' => 'GENERAL_SERVICE_BAY', 'capacity' => $capacity, 'status' => $status,
        ]);
    }

    /** A Work Order of the scenario's workshop moved to $status through the real transitions. */
    private function workOrder(array $s, string $status = 'DRAFT'): WorkOrder
    {
        $service = app(WorkOrderService::class);
        $vehicle = $this->makeVehicle($s['tenant'], $s['branch'], $s['category'], ['default_workshop_id' => $s['workshop']->id]);
        $wo = $service->create($vehicle, ['workshop_id' => $s['workshop']->id, 'maintenance_type' => 'CORRECTIVE'], $s['user']->id);
        $path = ['SUBMITTED' => 'submit', 'APPROVED' => 'approve', 'ASSIGNED' => 'assign', 'SCHEDULED' => 'schedule'];
        foreach ($path as $to => $method) {
            if ($wo->status === $status) {
                break;
            }
            $wo = $service->{$method}($wo);
        }
        if (in_array($status, ['IN_PROGRESS', 'QC_PENDING', 'COMPLETED'], true)) {
            $wo = $service->start($this->withApprovedWorkspace($wo));
            $wo = $status !== 'IN_PROGRESS' ? $service->submitToQc($wo) : $wo;
            $wo = $status === 'COMPLETED' ? $service->complete($wo) : $wo;
        }

        return $wo->fresh();
    }

    private function window(int $fromHours = 1, int $toHours = 3): array
    {
        return ['start_at' => now()->addHours($fromHours)->toIso8601String(), 'end_at' => now()->addHours($toHours)->toIso8601String()];
    }

    private function schedule(array $s, WorkOrder $wo, Workspace $workspace, ?array $window = null)
    {
        return $this->postJson('/api/v1/app/workspace-reservations', array_merge(['workspace_id' => $workspace->id, 'work_order_id' => $wo->id], $window ?? $this->window()), $s['headers']);
    }

    private function approved(array $s, WorkOrder $wo, Workspace $workspace, ?array $window = null): string
    {
        $id = $this->schedule($s, $wo, $workspace, $window)->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/workspace-reservations/{$id}/approve", [], $s['headers'])->assertOk()->assertJsonPath('data.status', 'APPROVED');

        return $id;
    }

    public function test_schedule_workspace_is_allowed_only_in_the_scheduling_statuses(): void
    {
        $s = $this->scenario();
        foreach (['DRAFT', 'SUBMITTED', 'APPROVED', 'ASSIGNED', 'SCHEDULED', 'QC_PENDING'] as $i => $status) {
            $wo = $this->workOrder($s, $status);
            $this->assertSame($status, $wo->status);
            if ($status === 'QC_PENDING') {
                // Its execution assignment is still current: re-scheduling at QC_PENDING is a transfer.
                $this->schedule($s, $wo, $s['bay3'], $this->window(10 + $i, 11 + $i))->assertStatus(422);

                continue;
            }
            $this->schedule($s, $wo, $s['bay3'], $this->window(10 + 2 * $i, 11 + 2 * $i))->assertStatus(201)->assertJsonPath('data.status', 'RESERVED');
        }
        foreach (['IN_PROGRESS', 'COMPLETED'] as $status) {
            $wo = $this->workOrder($s, $status);
            $this->schedule($s, $wo, $s['bay3'], $this->window(40, 41))->assertStatus(422);
        }
        // One current assignment per Work Order.
        $wo = $this->workOrder($s, 'APPROVED');
        $this->schedule($s, $wo, $s['bay3'], $this->window(50, 51))->assertStatus(201);
        $this->schedule($s, $wo, $s['bay2'], $this->window(50, 51))->assertStatus(422);
        // Cross-tenant workspace.
        $other = $this->scenario();
        $this->schedule($s, $this->workOrder($s), $other['bay'])->assertStatus(404);
    }

    public function test_scheduled_work_order_starts_only_with_an_approved_workspace_and_date(): void
    {
        $s = $this->scenario();
        $start = fn (WorkOrder $wo) => $this->postJson("/api/v1/app/work-orders/{$wo->id}/start", [], $s['headers']);

        // No workspace at all.
        $wo = $this->workOrder($s, 'SCHEDULED');
        $start($wo)->assertStatus(422)->assertJsonFragment(['message' => 'Cannot start this Work Order because no approved Workspace and scheduled work date are assigned.']);
        // Workspace requested but not approved.
        $reservationId = $this->schedule($s, $wo, $s['bay'])->assertStatus(201)->json('data.id');
        $start($wo)->assertStatus(422);
        // Approved, but its workspace no longer exists.
        $this->postJson("/api/v1/app/workspace-reservations/{$reservationId}/approve", [], $s['headers'])->assertOk();
        $s['bay']->delete();
        $start($wo)->assertStatus(422);
        $s['bay']->restore();
        // An approved assignment always carries a valid scheduled window (no date → refused by the database).
        $this->assertTrue($this->insertFails(fn () => DB::table('workspace_reservations')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $s['tenant']->id, 'workspace_id' => $s['bay2']->id, 'start_at' => now(), 'end_at' => now(),
            'status' => 'APPROVED', 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ])));
        // Approved workspace + date → allowed; the Work Order points at the approved workspace.
        $start($wo)->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
        $this->assertSame($s['bay']->id, $wo->fresh()->workspace_id);
    }

    private function insertFails(callable $insert): bool
    {
        try {
            DB::transaction(fn () => $insert());

            return false;
        } catch (QueryException) {
            return true;
        }
    }

    public function test_workspace_tab_shows_the_approved_assignment_with_its_approver(): void
    {
        $s = $this->scenario();
        $wo = $this->workOrder($s, 'ASSIGNED');
        $id = $this->schedule($s, $wo, $s['bay2'])->json('data.id');
        $show = fn () => $this->getJson("/api/v1/app/work-orders/{$wo->id}", $s['headers'])->assertOk()->json('data.workspace_reservations.0');
        $this->assertSame('RESERVED', $show()['status']);
        $this->assertNull($show()['approved_at']);

        $this->postJson("/api/v1/app/workspace-reservations/{$id}/approve", [], $s['headers'])->assertOk();
        $row = $show();
        $this->assertSame('APPROVED', $row['status']);
        $this->assertSame('BAY-2', $row['workspace']['code']);
        $this->assertSame(2, $row['workspace']['capacity']);
        $this->assertSame($s['workshop']->name, $row['workspace']['workshop']['name']);
        $this->assertSame($s['user']->name, $row['approver']['name']);
        $this->assertNotNull($row['approved_at']);
    }

    public function test_transfer_keeps_history_and_validates_the_target(): void
    {
        $s = $this->scenario();
        $wo = $this->workOrder($s, 'ASSIGNED');
        $pending = $this->schedule($s, $wo, $s['bay'])->json('data.id');
        $transfer = fn (string $id, array $body) => $this->postJson("/api/v1/app/workspace-reservations/{$id}/transfer", $body, $s['headers']);

        $transfer($pending, ['workspace_id' => $s['bay2']->id])->assertStatus(422); // not approved yet
        $this->postJson("/api/v1/app/workspace-reservations/{$pending}/approve", [], $s['headers'])->assertOk();

        $transfer($pending, ['workspace_id' => $s['bay']->id])->assertStatus(422); // same workspace
        $blocked = $this->workspace($s['tenant'], $s['workshop']->id, 'BAY-X', 5, 'BLOCKED');
        $transfer($pending, ['workspace_id' => $blocked->id])->assertStatus(422); // unavailable
        $other = $this->scenario();
        $transfer($pending, ['workspace_id' => $other['bay']->id])->assertStatus(422); // cross-tenant
        $elsewhere = $this->workspace($s['tenant'], $this->makeWorkshop($s['tenant'], $s['branch'])->id, 'BAY-Y', 1);
        $transfer($pending, ['workspace_id' => $elsewhere->id])->assertStatus(422); // other workshop
        $full = $this->workOrder($s, 'APPROVED');
        $this->approved($s, $full, $s['bay2'], $this->window(0, 4));
        $this->approved($s, $this->workOrder($s, 'APPROVED'), $s['bay2'], $this->window(0, 4));
        $transfer($pending, ['workspace_id' => $s['bay2']->id])->assertStatus(422); // over capacity

        $new = $transfer($pending, ['workspace_id' => $s['bay3']->id])->assertStatus(201)
            ->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.transferred_from_id', $pending)->json('data');
        $old = WorkspaceReservation::query()->findOrFail($pending);
        $this->assertSame('TRANSFERRED', $old->status);
        $this->assertSame($s['user']->id, $old->transferred_by);
        $this->assertNotNull($old->transferred_at);
        $this->assertSame($s['bay']->id, $old->workspace_id); // history keeps the previous workspace, dates and approval
        $this->assertNotNull($old->approved_at);
        $this->assertSame($s['bay3']->id, $wo->fresh()->workspace_id);
        $this->assertSame(1, WorkspaceReservation::query()->where('work_order_id', $wo->id)->whereIn('status', WorkspaceReservation::CURRENT)->count());
        $this->assertTrue(WorkspaceReservation::query()->find($new['id'])->start_at->equalTo($old->start_at)); // window carried over

        // A transferred (history) assignment cannot be transferred again.
        $transfer($pending, ['workspace_id' => $s['bay2']->id])->assertStatus(422);
    }

    public function test_transfer_requires_the_approve_permission(): void
    {
        $s = $this->scenario(['workspace.view', 'workspace.reserve']);
        $wo = $this->workOrder($s, 'ASSIGNED');
        $id = $this->schedule($s, $wo, $s['bay'])->assertStatus(201)->json('data.id');
        $this->postJson("/api/v1/app/workspace-reservations/{$id}/approve", [], $s['headers'])->assertStatus(403);
        WorkspaceReservation::query()->whereKey($id)->update(['status' => 'APPROVED', 'approved_at' => now()]);
        $this->postJson("/api/v1/app/workspace-reservations/{$id}/transfer", ['workspace_id' => $s['bay2']->id], $s['headers'])->assertStatus(403);
    }

    public function test_qc_pending_work_order_can_be_moved_to_another_workspace(): void
    {
        $s = $this->scenario();
        $wo = $this->workOrder($s, 'QC_PENDING');
        $current = WorkspaceReservation::query()->where('work_order_id', $wo->id)->firstOrFail();
        $this->assertSame('APPROVED', $current->status);

        $available = $this->getJson("/api/v1/app/work-orders/{$wo->id}/available-workspaces?".http_build_query($this->window(5, 6) + ['exclude_workspace_id' => $current->workspace_id]), $s['headers'])
            ->assertOk()->json('data');
        $this->assertNotContains($current->workspace_id, array_column($available, 'id'));

        $this->postJson("/api/v1/app/workspace-reservations/{$current->id}/transfer", ['workspace_id' => $s['bay3']->id] + $this->window(5, 6), $s['headers'])
            ->assertStatus(201)->assertJsonPath('data.status', 'APPROVED');
        $this->assertSame('TRANSFERRED', $current->fresh()->status);

        // Completing the Work Order completes only the current assignment; the transferred one stays history.
        app(WorkOrderService::class)->complete($wo->fresh());
        $this->assertSame('COMPLETED', WorkspaceReservation::query()->where('work_order_id', $wo->id)->where('transferred_from_id', $current->id)->value('status'));
        $this->assertSame('TRANSFERRED', $current->fresh()->status);
    }

    public function test_capacity_limits_concurrent_work_orders(): void
    {
        $s = $this->scenario();
        foreach (['bay' => 1, 'bay2' => 2, 'bay3' => 3] as $key => $capacity) {
            for ($n = 1; $n <= $capacity; $n++) {
                $this->schedule($s, $this->workOrder($s), $s[$key])->assertStatus(201);
            }
            $this->schedule($s, $this->workOrder($s), $s[$key])->assertStatus(422);
            // A window that does not overlap is free again.
            $this->schedule($s, $this->workOrder($s), $s[$key], $this->window(5, 6))->assertStatus(201);
        }

        // Capacity is about the same moment: two back-to-back windows plus one spanning both fit in capacity 2.
        $wide = $this->workspace($s['tenant'], $s['workshop']->id, 'BAY-W', 2);
        $this->schedule($s, $this->workOrder($s), $wide, $this->window(20, 21))->assertStatus(201);
        $this->schedule($s, $this->workOrder($s), $wide, $this->window(21, 22))->assertStatus(201);
        $this->schedule($s, $this->workOrder($s), $wide, $this->window(20, 22))->assertStatus(201);
        $this->schedule($s, $this->workOrder($s), $wide, $this->window(20, 22))->assertStatus(422);

        // The availability list hides full workspaces and reports occupancy.
        $rows = collect($this->getJson("/api/v1/app/work-orders/{$this->workOrder($s)->id}/available-workspaces?".http_build_query($this->window(1, 3)), $s['headers'])->assertOk()->json('data'))->keyBy('code');
        $this->assertFalse($rows->has('BAY-1'));
        $this->assertFalse($rows->has('BAY-2'));
        $this->assertFalse($rows->has('BAY-3'));
        $this->assertSame(0, $rows['BAY-W']['occupied']);

        // Cancelled assignments free their slot.
        $occupied = WorkspaceReservation::query()->where('workspace_id', $s['bay']->id)->where('status', 'RESERVED')->orderBy('start_at')->firstOrFail();
        $this->postJson("/api/v1/app/workspace-reservations/{$occupied->id}/cancel", [], $s['headers'])->assertOk();
        $this->schedule($s, $this->workOrder($s), $s['bay'])->assertStatus(201);
    }

    public function test_work_order_completion_completes_the_assignment_and_manual_complete_is_refused(): void
    {
        $s = $this->scenario();
        $wo = $this->workOrder($s, 'QC_PENDING');
        $assignment = WorkspaceReservation::query()->where('work_order_id', $wo->id)->firstOrFail();
        $this->postJson("/api/v1/app/workspace-reservations/{$assignment->id}/complete", [], $s['headers'])->assertStatus(422);
        $this->assertSame('APPROVED', $assignment->fresh()->status);

        $this->postJson("/api/v1/app/work-orders/{$wo->id}/complete", [], $s['headers'])->assertOk()->assertJsonPath('data.status', 'COMPLETED');
        $this->assertSame('COMPLETED', $assignment->fresh()->status);
        $this->assertNotNull($assignment->fresh()->completed_at);

        // A cancelled Work Order releases its assignment (pending or approved).
        $cancelled = $this->workOrder($s, 'APPROVED');
        $id = $this->approved($s, $cancelled, $s['bay3'], $this->window(30, 31));
        app(WorkOrderService::class)->cancel($cancelled);
        $this->assertSame('CANCELLED', WorkspaceReservation::query()->find($id)->status);
    }

    public function test_scheduler_returns_effective_capacity_and_current_assignments(): void
    {
        $s = $this->scenario();
        $wo = $this->workOrder($s, 'ASSIGNED');
        $this->approved($s, $wo, $s['bay2']);
        $this->schedule($s, $this->workOrder($s), $s['bay2'])->assertStatus(201);
        $noCapacity = $this->workspace($s['tenant'], $s['workshop']->id, 'BAY-N', null);

        $rows = collect($this->getJson('/api/v1/app/workshop-scheduler?'.http_build_query([
            'workshop_id' => $s['workshop']->id, 'from' => now()->toDateString(), 'to' => now()->addDays(6)->toDateString().' 23:59:59',
        ]), $s['headers'])->assertOk()->json('data'))->keyBy('code');
        $this->assertSame(2, $rows['BAY-2']['effective_capacity']);
        $this->assertSame(['APPROVED', 'RESERVED'], collect($rows['BAY-2']['reservations'])->pluck('status')->sort()->values()->all());
        $this->assertSame(1, $rows[$noCapacity->code]['effective_capacity']);
    }
}
