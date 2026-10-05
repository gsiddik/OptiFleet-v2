<?php

namespace App\Domain\Workshop\Services;

use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Services\WorkOrderException;
use App\Domain\Workshop\Models\Workspace;
use App\Domain\Workshop\Models\WorkspaceReservation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Workspace Assignment (a workspace reservation for a Work Order).
 *
 * Lifecycle (owner decision): RESERVED (requested) → APPROVED → COMPLETED, which only the Work
 * Order's own completion triggers; an approved assignment is TRANSFERRED to another workspace by
 * creating a new, immediately approved assignment (history kept, linked by transferred_from_id);
 * CANCELLED releases it. Legacy ACTIVE rows count as approved. A Work Order has at most one
 * current (RESERVED / APPROVED / ACTIVE) assignment — partial unique index.
 *
 * Capacity: a workspace with capacity N (null = 1) holds at most N assignments at the same time —
 * the peak number of overlapping current assignments inside the requested window is checked.
 * Section 32 concurrency: the Workspace row is locked (SELECT … FOR UPDATE) before the check, so
 * concurrent requests for the same workspace serialize instead of both seeing a free slot.
 */
class WorkspaceReservationService
{
    /** Work Order statuses in which Schedule Workspace may create an assignment. */
    public const SCHEDULABLE_WORK_ORDER_STATUSES = ['DRAFT', 'SUBMITTED', 'APPROVED', 'ASSIGNED', 'SCHEDULED', 'QC_PENDING'];

    /** Work Order statuses in which an approved assignment may be transferred. */
    public const TRANSFERABLE_WORK_ORDER_STATUSES = ['DRAFT', 'SUBMITTED', 'APPROVED', 'ASSIGNED', 'SCHEDULED', 'IN_PROGRESS', 'ON_HOLD', 'WAITING_PART', 'REWORK', 'QC_PENDING'];

    /** Work has started: an approved workspace can then only be transferred, not cancelled. */
    private const STARTED_WORK_ORDER_STATUSES = ['IN_PROGRESS', 'ON_HOLD', 'WAITING_PART', 'REWORK', 'QC_PENDING'];

    public const UNAVAILABLE_WORKSPACE_STATUSES = ['BLOCKED', 'UNDER_MAINTENANCE', 'INACTIVE'];

    public function reserve(Workspace $workspace, \DateTimeInterface $startAt, \DateTimeInterface $endAt, ?string $workOrderId = null, ?string $createdByUserId = null): WorkspaceReservation
    {
        $this->assertWindow($startAt, $endAt);
        $workOrder = $workOrderId ? WorkOrder::query()->withoutGlobalScopes()->find($workOrderId) : null;
        if ($workOrderId && (! $workOrder || $workOrder->tenant_id !== $workspace->tenant_id)) {
            throw new WorkshopOpsException('The Work Order was not found.');
        }
        // Workspace is an internal-workshop-execution capability — never usable for an External
        // Work Order (Findings-only scope carried out by an external workshop).
        if ($workOrder?->status === 'EXTERNAL') {
            throw new WorkshopOpsException('Workspace reservations are not available for an External Work Order.');
        }
        if ($workOrder && ! in_array($workOrder->status, self::SCHEDULABLE_WORK_ORDER_STATUSES, true)) {
            throw new WorkshopOpsException("A workspace cannot be scheduled for a Work Order in status {$workOrder->status}.");
        }

        try {
            return DB::transaction(function () use ($workspace, $startAt, $endAt, $workOrder, $createdByUserId) {
                $locked = Workspace::query()->withoutGlobalScopes()->whereNull('deleted_at')->lockForUpdate()->findOrFail($workspace->id);
                if ($workOrder) {
                    $this->assertCompatible($locked, $workOrder);
                    if ($this->current($workOrder->id)) {
                        throw new WorkshopOpsException('This Work Order already has a current workspace assignment — transfer it to another workspace instead.');
                    }
                }
                $this->assertAvailable($locked, $startAt, $endAt);

                return WorkspaceReservation::query()->create([
                    'tenant_id' => $locked->tenant_id, 'workspace_id' => $locked->id, 'work_order_id' => $workOrder?->id,
                    'start_at' => $startAt, 'end_at' => $endAt, 'status' => WorkspaceReservation::RESERVED, 'created_by' => $createdByUserId,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw new WorkshopOpsException('This Work Order already has a current workspace assignment — transfer it to another workspace instead.');
        }
    }

    /** Approve a requested assignment; the Work Order's workspace reference follows it. */
    public function approve(WorkspaceReservation $reservation, string $userId): WorkspaceReservation
    {
        return DB::transaction(function () use ($reservation, $userId) {
            $locked = WorkspaceReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if ($locked->status !== WorkspaceReservation::RESERVED) {
                throw new WorkshopOpsException("Only a requested (RESERVED) assignment can be approved; this one is {$locked->status}.");
            }
            $workOrder = $locked->work_order_id ? WorkOrder::query()->withoutGlobalScopes()->find($locked->work_order_id) : null;
            if ($workOrder && ! in_array($workOrder->status, self::TRANSFERABLE_WORK_ORDER_STATUSES, true)) {
                throw new WorkshopOpsException("The Work Order is {$workOrder->status}; its workspace can no longer be approved.");
            }
            $locked->update(['status' => WorkspaceReservation::APPROVED, 'approved_by' => $userId, 'approved_at' => now()]);
            $this->syncWorkOrder($locked);

            return $locked->fresh();
        });
    }

    /**
     * Transfer to Another Workspace (owner decision: approved immediately). The current assignment is
     * kept as TRANSFERRED history; a new APPROVED assignment is created for the target workspace.
     */
    public function transfer(WorkspaceReservation $reservation, Workspace $target, ?\DateTimeInterface $startAt, ?\DateTimeInterface $endAt, string $userId): WorkspaceReservation
    {
        return DB::transaction(function () use ($reservation, $target, $startAt, $endAt, $userId) {
            $current = WorkspaceReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if (! in_array($current->status, WorkspaceReservation::APPROVED_STATES, true)) {
                throw new WorkshopOpsException("Only an approved assignment can be transferred; this one is {$current->status}.");
            }
            $workOrder = $current->work_order_id ? WorkOrder::query()->withoutGlobalScopes()->find($current->work_order_id) : null;
            if ($workOrder && ! in_array($workOrder->status, self::TRANSFERABLE_WORK_ORDER_STATUSES, true)) {
                throw new WorkshopOpsException("A Work Order in status {$workOrder->status} cannot be transferred to another workspace.");
            }
            if ($target->tenant_id !== $current->tenant_id) {
                throw new WorkshopOpsException('The target workspace was not found.');
            }
            if ($target->id === $current->workspace_id) {
                throw new WorkshopOpsException('Choose a different workspace — the Work Order is already assigned to this one.');
            }
            $startAt ??= $current->start_at;
            $endAt ??= $current->end_at;
            $this->assertWindow($startAt, $endAt);

            $locked = Workspace::query()->withoutGlobalScopes()->whereNull('deleted_at')->lockForUpdate()->findOrFail($target->id);
            $source = Workspace::query()->withoutGlobalScopes()->find($current->workspace_id);
            if ($source && $locked->workshop_id !== $source->workshop_id) {
                throw new WorkshopOpsException('The target workspace must belong to the same workshop.');
            }
            if ($workOrder) {
                $this->assertCompatible($locked, $workOrder);
            }
            $this->assertAvailable($locked, $startAt, $endAt);

            $current->update(['status' => WorkspaceReservation::TRANSFERRED, 'transferred_by' => $userId, 'transferred_at' => now()]);
            $new = WorkspaceReservation::query()->create([
                'tenant_id' => $current->tenant_id, 'workspace_id' => $locked->id, 'work_order_id' => $current->work_order_id,
                'start_at' => $startAt, 'end_at' => $endAt, 'status' => WorkspaceReservation::APPROVED,
                'created_by' => $userId, 'approved_by' => $userId, 'approved_at' => now(), 'transferred_from_id' => $current->id,
            ]);
            $this->syncWorkOrder($new);

            return $new->fresh();
        });
    }

    public function cancel(WorkspaceReservation $reservation): WorkspaceReservation
    {
        return DB::transaction(function () use ($reservation) {
            $locked = WorkspaceReservation::query()->lockForUpdate()->findOrFail($reservation->id);
            if (! in_array($locked->status, WorkspaceReservation::CURRENT, true)) {
                throw new WorkshopOpsException("Cannot cancel a reservation already {$locked->status}.");
            }
            $workOrder = $locked->work_order_id ? WorkOrder::query()->withoutGlobalScopes()->find($locked->work_order_id) : null;
            if ($workOrder && $locked->status !== WorkspaceReservation::RESERVED && in_array($workOrder->status, self::STARTED_WORK_ORDER_STATUSES, true)) {
                throw new WorkshopOpsException('Work on this Work Order has started — transfer its workspace instead of cancelling it.');
            }
            $locked->update(['status' => WorkspaceReservation::CANCELLED, 'cancelled_at' => now()]);

            return $locked->fresh();
        });
    }

    /** Replaced by Approve (the approval model). Kept so old clients get a clear 422. */
    public function activate(WorkspaceReservation $reservation): WorkspaceReservation
    {
        throw new WorkshopOpsException('Activate is no longer available — approve the Workspace Assignment instead.');
    }

    /** Manual completion was removed: an assignment completes only with its Work Order. */
    public function complete(WorkspaceReservation $reservation): WorkspaceReservation
    {
        throw new WorkshopOpsException('A Workspace Assignment completes automatically when its Work Order is completed.');
    }

    /** The Work Order's current assignment (RESERVED / APPROVED / ACTIVE), if any. */
    public function current(string $workOrderId): ?WorkspaceReservation
    {
        return WorkspaceReservation::query()->withoutGlobalScopes()->where('work_order_id', $workOrderId)
            ->whereIn('status', WorkspaceReservation::CURRENT)->latest('created_at')->first();
    }

    /**
     * SCHEDULED → IN_PROGRESS precondition: an approved assignment on an existing workspace with a
     * valid scheduled window. Runs inside the Work Order transition transaction.
     */
    public function assertStartable(WorkOrder $workOrder): void
    {
        $current = WorkspaceReservation::query()->withoutGlobalScopes()->where('work_order_id', $workOrder->id)
            ->whereIn('status', WorkspaceReservation::APPROVED_STATES)->lockForUpdate()->first();
        $workspaceExists = $current && Workspace::query()->withoutGlobalScopes()->whereKey($current->workspace_id)->whereNull('deleted_at')->exists();
        $validWindow = $current && $current->start_at && $current->end_at && $current->end_at->gt($current->start_at);
        if (! $current || ! $workspaceExists || ! $validWindow) {
            throw new WorkOrderException('Cannot start this Work Order because no approved Workspace and scheduled work date are assigned.');
        }
    }

    /** Work Order COMPLETED → its approved assignment COMPLETED; a still-pending request is cancelled. */
    public function completeForWorkOrder(WorkOrder $workOrder): void
    {
        $now = now();
        WorkspaceReservation::query()->withoutGlobalScopes()->where('work_order_id', $workOrder->id)
            ->whereIn('status', WorkspaceReservation::APPROVED_STATES)->update(['status' => WorkspaceReservation::COMPLETED, 'completed_at' => $now]);
        $this->releaseForWorkOrder($workOrder);
    }

    /** Work Order cancelled / rejected (or completed with a pending request): release its slot. */
    public function releaseForWorkOrder(WorkOrder $workOrder): void
    {
        WorkspaceReservation::query()->withoutGlobalScopes()->where('work_order_id', $workOrder->id)
            ->whereIn('status', WorkspaceReservation::CURRENT)->update(['status' => WorkspaceReservation::CANCELLED, 'cancelled_at' => now()]);
    }

    public function capacity(Workspace $workspace): int
    {
        return max(1, (int) ($workspace->capacity ?? 1));
    }

    /** Peak number of current assignments of the workspace overlapping at the same moment inside the window. */
    public function peakOccupancy(string $workspaceId, \DateTimeInterface $startAt, \DateTimeInterface $endAt, ?string $excludeReservationId = null): int
    {
        $windows = WorkspaceReservation::query()->withoutGlobalScopes()->where('workspace_id', $workspaceId)
            ->whereIn('status', WorkspaceReservation::CURRENT)
            ->where('start_at', '<', $endAt)->where('end_at', '>', $startAt)
            ->when($excludeReservationId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->get(['start_at', 'end_at'])->toBase();
        $peak = 0;
        $points = $windows->map(fn ($w) => max($w->start_at->getTimestamp(), $startAt->getTimestamp()))->push($startAt->getTimestamp())->unique();
        foreach ($points as $point) {
            $peak = max($peak, $windows->filter(fn ($w) => $w->start_at->getTimestamp() <= $point && $w->end_at->getTimestamp() > $point)->count());
        }

        return $peak;
    }

    /**
     * Workspaces of the Work Order's workshop that can take it in the window: not blocked / under
     * maintenance / inactive, compatible with the vehicle category, and with a free capacity slot.
     *
     * @return Collection<int, array{workspace: Workspace, capacity: int, occupied: int}>
     */
    public function available(WorkOrder $workOrder, \DateTimeInterface $startAt, \DateTimeInterface $endAt, ?string $excludeWorkspaceId = null): Collection
    {
        $this->assertWindow($startAt, $endAt);
        $categoryId = Vehicle::query()->withoutGlobalScopes()->whereKey($workOrder->vehicle_id)->value('vehicle_category_id');

        return Workspace::query()->withoutGlobalScopes()->whereNull('deleted_at')->with('vehicleCategories:id')
            ->where('tenant_id', $workOrder->tenant_id)->where('workshop_id', $workOrder->workshop_id)
            ->whereNotIn('status', self::UNAVAILABLE_WORKSPACE_STATUSES)
            ->when($excludeWorkspaceId, fn ($q, $id) => $q->where('id', '!=', $id))
            ->orderBy('code')->get()
            ->filter(fn (Workspace $w) => $w->vehicleCategories->isEmpty() || $w->vehicleCategories->contains('id', $categoryId))
            ->map(fn (Workspace $w) => ['workspace' => $w, 'capacity' => $this->capacity($w), 'occupied' => $this->peakOccupancy($w->id, $startAt, $endAt)])
            ->filter(fn (array $row) => $row['occupied'] < $row['capacity'])
            ->values();
    }

    private function assertAvailable(Workspace $workspace, \DateTimeInterface $startAt, \DateTimeInterface $endAt): void
    {
        if (in_array($workspace->status, self::UNAVAILABLE_WORKSPACE_STATUSES, true)) {
            throw new WorkshopOpsException("Workspace {$workspace->name} is {$workspace->status} and cannot be scheduled.");
        }
        $capacity = $this->capacity($workspace);
        if ($this->peakOccupancy($workspace->id, $startAt, $endAt) >= $capacity) {
            throw new WorkshopOpsException($capacity === 1
                ? 'This workspace already has an overlapping reservation for that time window.'
                : "Workspace {$workspace->name} is full for that time window (capacity {$capacity}).");
        }
    }

    private function assertCompatible(Workspace $workspace, WorkOrder $workOrder): void
    {
        if ($workspace->workshop_id !== $workOrder->workshop_id) {
            throw new WorkshopOpsException("Workspace {$workspace->name} belongs to another workshop than this Work Order.");
        }
        $categories = $workspace->vehicleCategories()->pluck('vehicle_categories.id');
        $categoryId = Vehicle::query()->withoutGlobalScopes()->whereKey($workOrder->vehicle_id)->value('vehicle_category_id');
        if ($categories->isNotEmpty() && ! $categories->contains($categoryId)) {
            throw new WorkshopOpsException("Workspace {$workspace->name} does not accept this vehicle's category.");
        }
    }

    private function assertWindow(?\DateTimeInterface $startAt, ?\DateTimeInterface $endAt): void
    {
        if (! $startAt || ! $endAt || $startAt >= $endAt) {
            throw new WorkshopOpsException('Reservation start must be before its end.');
        }
    }

    /** work_orders.workspace_id mirrors the approved assignment (the assignment is the source of truth). */
    private function syncWorkOrder(WorkspaceReservation $reservation): void
    {
        if ($reservation->work_order_id) {
            WorkOrder::query()->withoutGlobalScopes()->whereKey($reservation->work_order_id)->update(['workspace_id' => $reservation->workspace_id]);
        }
    }
}
