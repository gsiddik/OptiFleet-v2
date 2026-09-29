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
 * additional-work request/approve/reject sub-flow.
 *
 * Three distinct gates, per "Improvement OptiFleet - Maintenance Request dan
 * Work Order" Section (Work Order per-status tab behavior):
 * - Findings/Diagnosis/Corrective Actions are a Draft-only scoping exercise:
 *   the Complaint/Diagnosis tabs' Add controls (and Delete/Remove) are only
 *   ever shown while status=DRAFT, hidden (not just disabled) afterward.
 * - Jobs/Mechanic (assign)/Planned Parts stay addable from Draft all the way
 *   through the active execution window (through On Hold/Waiting Part/
 *   Rework) — PLANNING_STATUSES / assertPlanningEditable(). This is
 *   deliberately its own gate, NOT the same as EXECUTABLE_STATUSES below:
 *   Part Requests (a separate tab/service from Planned Parts) and
 *   Reserve/Issue/Return (WorkOrderPartService) only become available once
 *   the WO reaches IN_PROGRESS ("Issuance & Return", formerly "Request Parts", is introduced there, and
 *   Draft's Planned Parts tab explicitly hides Reserve/Issue/Consume/Return)
 *   — those keep using the original, narrower EXECUTABLE_STATUSES.
 * - Everything else already gated by assertExecutable() (Part Requests,
 *   External Services, Additional Work) is unchanged from before this batch.
 */
class WorkOrderExecutionService
{
    private const EXECUTABLE_STATUSES = ['ASSIGNED', 'SCHEDULED', 'IN_PROGRESS', 'ON_HOLD', 'WAITING_PART', 'REWORK'];
    private const PLANNING_STATUSES = ['DRAFT', 'SUBMITTED', 'APPROVED', 'ASSIGNED', 'SCHEDULED', 'IN_PROGRESS', 'ON_HOLD', 'WAITING_PART', 'REWORK'];
    private const FINDING_SCOPE_STATUSES = ['DRAFT'];

    public function addFinding(WorkOrder $workOrder, array $attributes, ?string $userId = null): WorkOrderFinding
    {
        $this->assertFindingScopeEditable($workOrder);

        return WorkOrderFinding::query()->create(array_merge($attributes, [
            'work_order_id' => $workOrder->id,
            'status' => 'OPEN',
            'created_by' => $userId,
        ]));
    }

    public function deleteFinding(WorkOrderFinding $finding): void
    {
        $workOrder = WorkOrder::query()->findOrFail($finding->work_order_id);
        $this->assertFindingScopeEditable($workOrder);
        $finding->delete();
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
        $this->assertFindingScopeEditable($workOrder);

        return WorkOrderDiagnosis::query()->create(array_merge($attributes, [
            'work_order_id' => $workOrder->id,
            'diagnosed_by' => $userId,
            'diagnosed_at' => now(),
        ]));
    }

    public function deleteDiagnosis(WorkOrderDiagnosis $diagnosis): void
    {
        $workOrder = WorkOrder::query()->findOrFail($diagnosis->work_order_id);
        $this->assertFindingScopeEditable($workOrder);
        $diagnosis->delete();
    }

    public function addCorrectiveAction(WorkOrder $workOrder, array $attributes): WorkOrderCorrectiveAction
    {
        $this->assertFindingScopeEditable($workOrder);

        return WorkOrderCorrectiveAction::query()->create(array_merge($attributes, ['work_order_id' => $workOrder->id]));
    }

    public function deleteCorrectiveAction(WorkOrderCorrectiveAction $correctiveAction): void
    {
        $workOrder = WorkOrder::query()->findOrFail($correctiveAction->work_order_id);
        $this->assertFindingScopeEditable($workOrder);
        $correctiveAction->delete();
    }

    public function addJob(WorkOrder $workOrder, array $attributes): MaintenanceJob
    {
        $this->assertPlanningEditable($workOrder);

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
        $this->assertPlanningEditable($workOrder);

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
        $this->assertNotExternalMode($workOrder);
        if (! in_array($workOrder->status, self::EXECUTABLE_STATUSES, true)) {
            throw new WorkOrderException("Work Order execution actions are not allowed while status is {$workOrder->status}.");
        }
    }

    public function assertFindingScopeEditable(WorkOrder $workOrder): void
    {
        $this->assertNotExternalMode($workOrder);
        if (! in_array($workOrder->status, self::FINDING_SCOPE_STATUSES, true)) {
            throw new WorkOrderException("Findings and Diagnosis can only be added or removed while status is Draft (currently {$workOrder->status}).");
        }
    }

    public function assertPlanningEditable(WorkOrder $workOrder): void
    {
        $this->assertNotExternalMode($workOrder);
        if (! in_array($workOrder->status, self::PLANNING_STATUSES, true)) {
            throw new WorkOrderException("Jobs, Mechanic, and Planned Parts cannot be added while status is {$workOrder->status}.");
        }
    }

    /**
     * "Consolidated External Workshop business rules": every internal-workshop capability
     * (Findings/Diagnosis/Corrective Actions/Jobs/Mechanic/Planned Parts) is off-limits for an
     * External-mode Work Order at any status — it only ever uses the separate
     * external-findings/external/* endpoints. Without this, adding DRAFT to
     * EXECUTABLE_STATUSES/FINDING_SCOPE_STATUSES (so internal Draft WOs can use these tabs)
     * would also open them up on a Draft External-mode WO, which must never happen.
     */
    private function assertNotExternalMode(WorkOrder $workOrder): void
    {
        if ($workOrder->execution_mode === 'EXTERNAL') {
            throw new WorkOrderException('This action is not available for an External-mode Work Order.');
        }
    }
}
