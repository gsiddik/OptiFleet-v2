<?php

namespace App\Domain\Workshop\Services;

use App\Domain\Workshop\Models\Worker;
use App\Domain\Workshop\Models\WorkshopWorkerAssignment;
use Illuminate\Support\Facades\DB;

/**
 * Mirrors VehicleAssignmentService (Section 5): closes the worker's open
 * assignment period and opens a new one rather than overwriting the
 * worker's current branch/workshop in place, so reassignment history is
 * always available.
 */
class WorkerAssignmentService
{
    public function assign(Worker $worker, array $attributes, ?string $assignedByUserId = null): WorkshopWorkerAssignment
    {
        return DB::transaction(function () use ($worker, $attributes, $assignedByUserId) {
            $worker = Worker::query()->lockForUpdate()->findOrFail($worker->id);

            $toBranchId = $attributes['branch_id'] ?? $worker->branch_id;
            $toWorkshopId = array_key_exists('workshop_id', $attributes) ? $attributes['workshop_id'] : $worker->workshop_id;
            $today = now()->toDateString();

            WorkshopWorkerAssignment::query()
                ->where('worker_id', $worker->id)
                ->whereNull('effective_until')
                ->update(['effective_until' => $today]);

            $assignment = WorkshopWorkerAssignment::query()->create([
                'tenant_id' => $worker->tenant_id,
                'worker_id' => $worker->id,
                'from_branch_id' => $worker->branch_id,
                'to_branch_id' => $toBranchId,
                'from_workshop_id' => $worker->workshop_id,
                'to_workshop_id' => $toWorkshopId,
                'effective_from' => $today,
                'assigned_by' => $assignedByUserId,
            ]);

            $worker->update(['branch_id' => $toBranchId, 'workshop_id' => $toWorkshopId]);

            return $assignment;
        });
    }
}
