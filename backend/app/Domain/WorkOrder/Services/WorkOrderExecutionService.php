<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\WorkOrder\Models\MaintenanceJob;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderAdditionalWork;
use App\Domain\WorkOrder\Models\WorkOrderCorrectiveAction;
use App\Domain\WorkOrder\Models\WorkOrderDiagnosis;
use App\Domain\WorkOrder\Models\WorkOrderFinding;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use Illuminate\Support\Facades\DB;

/**
 * Sections 25/26/34/35: Complaint -> Finding -> Diagnosis -> Root Cause ->
 * Corrective Action, the WO's job list, planned parts (reservation/issue/
 * return lifecycle lives in WorkOrderPartService, Phase 4), and the
 * additional-work request/approve/reject sub-flow. A WO
 * only accepts execution activity while genuinely being worked
 * (IN_PROGRESS/ON_HOLD/WAITING_PART) or still open for review
 * (ASSIGNED/SCHEDULED) — never once it has left the active workflow.
 */
class WorkOrderExecutionService
{
    private const EXECUTABLE_STATUSES = ['ASSIGNED', 'SCHEDULED', 'IN_PROGRESS', 'ON_HOLD', 'WAITING_PART', 'REWORK'];

    public function addFinding(WorkOrder $workOrder, array $attributes, ?string $userId = null): WorkOrderFinding
    {
        $this->assertExecutable($workOrder);

        return WorkOrderFinding::query()->create(array_merge($attributes, [
            'work_order_id' => $workOrder->id,
            'status' => 'OPEN',
            'created_by' => $userId,
        ]));
    }

    /**
     * G-04: intentionally NOT gated by assertExecutable() — a finding must
     * be resolvable while the WO sits in QC_PENDING/REWORK/etc. on its way
     * toward COMPLETED, which is exactly when WorkOrderClosureGuardService
     * needs it to already be resolved.
     */
    public function resolveFinding(WorkOrderFinding $finding, ?string $notes, ?string $userId): WorkOrderFinding
    {
        return DB::transaction(function () use ($finding, $notes, $userId) {
            $locked = WorkOrderFinding::query()->lockForUpdate()->findOrFail($finding->id);
            if ($locked->status === 'RESOLVED') {
                throw new WorkOrderException('This finding is already resolved.');
            }
            $locked->update(['status' => 'RESOLVED', 'resolution_notes' => $notes, 'resolved_by' => $userId, 'resolved_at' => now()]);

            return $locked->fresh();
        });
    }

    public function addDiagnosis(WorkOrder $workOrder, array $attributes, ?string $userId = null): WorkOrderDiagnosis
    {
        $this->assertExecutable($workOrder);

        return WorkOrderDiagnosis::query()->create(array_merge($attributes, [
            'work_order_id' => $workOrder->id,
            'diagnosed_by' => $userId,
            'diagnosed_at' => now(),
        ]));
    }

    public function addCorrectiveAction(WorkOrder $workOrder, array $attributes): WorkOrderCorrectiveAction
    {
        $this->assertExecutable($workOrder);

        return WorkOrderCorrectiveAction::query()->create(array_merge($attributes, ['work_order_id' => $workOrder->id]));
    }

    public function addJob(WorkOrder $workOrder, array $attributes): MaintenanceJob
    {
        $this->assertExecutable($workOrder);

        return MaintenanceJob::query()->create(array_merge($attributes, [
            'work_order_id' => $workOrder->id,
            'status' => 'PENDING',
        ]));
    }

    public function updateJobStatus(MaintenanceJob $job, string $status): MaintenanceJob
    {
        $allowed = [
            'PENDING' => ['ASSIGNED', 'CANCELLED'],
            'ASSIGNED' => ['IN_PROGRESS', 'CANCELLED'],
            'IN_PROGRESS' => ['ON_HOLD', 'COMPLETED', 'CANCELLED'],
            'ON_HOLD' => ['IN_PROGRESS', 'CANCELLED'],
        ];

        if (! in_array($status, $allowed[$job->status] ?? [], true)) {
            throw new WorkOrderException("Cannot transition maintenance job from {$job->status} to {$status}.");
        }

        $extra = match ($status) {
            'IN_PROGRESS' => ['started_at' => $job->started_at ?? now()],
            'COMPLETED' => ['completed_at' => now()],
            default => [],
        };

        $job->update(array_merge($extra, ['status' => $status]));

        return $job->fresh();
    }

    public function addPlannedPart(WorkOrder $workOrder, array $attributes): WorkOrderPlannedPart
    {
        $this->assertExecutable($workOrder);

        $quantity = (float) ($attributes['quantity'] ?? 1);

        return WorkOrderPlannedPart::query()->create(array_merge($attributes, [
            'tenant_id' => $workOrder->tenant_id,
            'work_order_id' => $workOrder->id,
            'quantity' => $quantity,
            'planned_quantity' => $quantity,
            'status' => 'PLANNED',
        ]));
    }

    public function requestAdditionalWork(WorkOrder $workOrder, string $description, ?string $userId = null): WorkOrderAdditionalWork
    {
        $this->assertExecutable($workOrder);

        return WorkOrderAdditionalWork::query()->create([
            'work_order_id' => $workOrder->id,
            'description' => $description,
            'requested_by' => $userId,
            'status' => 'REQUESTED',
            'requested_at' => now(),
        ]);
    }

    public function decideAdditionalWork(WorkOrderAdditionalWork $additionalWork, bool $approve, ?string $userId = null, ?string $note = null): WorkOrderAdditionalWork
    {
        return DB::transaction(function () use ($additionalWork, $approve, $userId, $note) {
            $additionalWork = WorkOrderAdditionalWork::query()->lockForUpdate()->findOrFail($additionalWork->id);

            if ($additionalWork->status !== 'REQUESTED') {
                throw new WorkOrderException('Only a requested additional work item can be decided.');
            }

            $additionalWork->update([
                'status' => $approve ? 'APPROVED' : 'REJECTED',
                'decided_by' => $userId,
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            if ($approve) {
                MaintenanceJob::query()->create([
                    'work_order_id' => $additionalWork->work_order_id,
                    'description' => $additionalWork->description,
                    'status' => 'PENDING',
                ]);
            }

            return $additionalWork->fresh();
        });
    }

    public function assertExecutable(WorkOrder $workOrder): void
    {
        if (! in_array($workOrder->status, self::EXECUTABLE_STATUSES, true)) {
            throw new WorkOrderException("Work Order execution actions are not allowed while status is {$workOrder->status}.");
        }
    }
}
