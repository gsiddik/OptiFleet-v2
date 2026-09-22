<?php

namespace App\Domain\Workshop\Services;

use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Services\WorkOrderExecutionService;
use App\Domain\Workshop\Models\Worker;
use App\Domain\Workshop\Models\WorkOrderMechanicAssignment;
use Illuminate\Support\Facades\DB;

/**
 * Section 28: a worker can only be assigned to a Work Order in their own
 * workshop — cross-workshop assignment is rejected outright, not merely
 * hidden in the UI (Section 61's "frontend guards are UX only"). Assigning
 * a new PRIMARY closes out the previous one (unassigned_at) rather than
 * leaving two concurrent primaries. Mechanic tab is editable across the same
 * status window as Jobs/Planned Parts (WorkOrderExecutionService::
 * assertExecutable) — previously unguarded here entirely.
 */
class MechanicAssignmentService
{
    public function __construct(private readonly WorkOrderExecutionService $execution) {}

    public function assign(WorkOrder $workOrder, Worker $worker, string $role = 'PRIMARY', ?string $jobId = null, ?string $assignedByUserId = null): WorkOrderMechanicAssignment
    {
        $this->execution->assertPlanningEditable($workOrder);

        if ($worker->tenant_id !== $workOrder->tenant_id) {
            throw new WorkshopOpsException('Cannot assign a worker from another tenant.');
        }
        if ($worker->workshop_id !== $workOrder->workshop_id) {
            throw new WorkshopOpsException('Cannot assign a worker outside the Work Order\'s workshop.');
        }

        return DB::transaction(function () use ($workOrder, $worker, $role, $jobId, $assignedByUserId) {
            if ($role === 'PRIMARY') {
                WorkOrderMechanicAssignment::query()
                    ->where('work_order_id', $workOrder->id)
                    ->where('maintenance_job_id', $jobId)
                    ->where('role', 'PRIMARY')
                    ->whereNull('unassigned_at')
                    ->update(['unassigned_at' => now()]);
            }

            return WorkOrderMechanicAssignment::query()->create([
                'work_order_id' => $workOrder->id,
                'maintenance_job_id' => $jobId,
                'worker_id' => $worker->id,
                'role' => $role,
                // Snapshotted at assignment time so a later rate change never retroactively
                // changes the cost basis of already-assigned work (same pattern as
                // work_order_planned_parts.unit_cost_at_issue).
                'hourly_rate_snapshot' => $worker->hourly_rate,
                'assigned_at' => now(),
                'assigned_by' => $assignedByUserId,
            ]);
        });
    }

    public function unassign(WorkOrderMechanicAssignment $assignment): WorkOrderMechanicAssignment
    {
        $assignment->update(['unassigned_at' => now()]);

        return $assignment->fresh();
    }
}
