<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderExternalReference;
use App\Domain\WorkOrder\Models\WorkOrderFinding;
use Illuminate\Support\Facades\DB;

/**
 * Consolidated External Workshop business rules — deliberately separate
 * from WorkOrderExecutionService (internal-workshop execution). An
 * External Work Order uses only Findings as its scope of work; every
 * internal-workshop capability (Diagnosis, Corrective Actions, Jobs,
 * Mechanic Assignment, Planned/Request Parts, Workspace, QC, Road Test) is
 * out of reach here by construction — this service never touches
 * WorkOrderExecutionService::EXECUTABLE_STATUSES, and adds no EXTERNAL
 * entry there.
 */
class ExternalWorkOrderService
{
    private const RESOURCE_TYPE = 'work_order';

    public function __construct(
        private readonly WorkOrderTransitionService $transitions,
    ) {}

    /** "Selecting External Workshop" — marks a Draft Work Order as External-destination. Idempotent. */
    public function markExternalMode(WorkOrder $workOrder): WorkOrder
    {
        return DB::transaction(function () use ($workOrder) {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            if ($locked->status !== 'DRAFT') {
                throw new WorkOrderException('External Workshop mode can only be selected while the Work Order is Draft.');
            }
            if ($locked->execution_mode !== 'EXTERNAL') {
                $locked->update(['execution_mode' => 'EXTERNAL']);
            }

            return $locked->fresh();
        });
    }

    private function assertDraftExternal(WorkOrder $workOrder): WorkOrder
    {
        $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
        if ($locked->status !== 'DRAFT' || $locked->execution_mode !== 'EXTERNAL') {
            throw new WorkOrderException('Findings can only be managed while this Work Order is a Draft in External mode.');
        }

        return $locked;
    }

    public function addFinding(WorkOrder $workOrder, array $attributes, ?string $userId = null): WorkOrderFinding
    {
        return DB::transaction(function () use ($workOrder, $attributes, $userId) {
            $this->assertDraftExternal($workOrder);

            return WorkOrderFinding::query()->create(array_merge($attributes, [
                'work_order_id' => $workOrder->id,
                'status' => 'OPEN',
                'created_by' => $userId,
            ]));
        });
    }

    public function updateFinding(WorkOrderFinding $finding, array $attributes): WorkOrderFinding
    {
        return DB::transaction(function () use ($finding, $attributes) {
            $lockedFinding = WorkOrderFinding::query()->lockForUpdate()->findOrFail($finding->id);
            $this->assertDraftExternal($lockedFinding->workOrder);
            $lockedFinding->update($attributes);

            return $lockedFinding->fresh();
        });
    }

    public function deleteFinding(WorkOrderFinding $finding): void
    {
        DB::transaction(function () use ($finding) {
            $lockedFinding = WorkOrderFinding::query()->lockForUpdate()->findOrFail($finding->id);
            $this->assertDraftExternal($lockedFinding->workOrder);
            $lockedFinding->delete();
        });
    }

    /**
     * Finalize (initial: DRAFT -> EXTERNAL, or re-finalize after a revision:
     * DRAFT_EXTERNAL_MODE -> EXTERNAL). Idempotent: a request against an
     * already-EXTERNAL Work Order with an unchanged Finding set returns the
     * current state rather than erroring or double-incrementing.
     */
    public function finalize(WorkOrder $workOrder, ?string $actorUserId = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $actorUserId) {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);

            if ($locked->status === 'EXTERNAL') {
                return $locked; // idempotent: already finalized, nothing to do.
            }

            if ($locked->status !== 'DRAFT' || $locked->execution_mode !== 'EXTERNAL') {
                throw new WorkOrderException('Only a Draft Work Order in External mode can be finalized as External.');
            }

            $findingCount = WorkOrderFinding::query()->where('work_order_id', $locked->id)->count();
            if ($findingCount < 1) {
                throw new WorkOrderException('At least one Finding is required before finalizing this Work Order as External.');
            }

            $updated = $this->transitions->transition($locked, 'EXTERNAL', [
                'external_finalized_revision' => $locked->external_finalized_revision + 1,
            ]);

            WorkOrderExternalReference::query()->firstOrCreate(
                ['work_order_id' => $updated->id],
                ['tenant_id' => $updated->tenant_id, 'branch_id' => $updated->branch_id]
            );

            return $updated;
        });
    }

    /** Revise: EXTERNAL -> DRAFT, keeping External mode, WO number, and the last finalized revision. */
    public function revise(WorkOrder $workOrder): WorkOrder
    {
        return DB::transaction(function () use ($workOrder) {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);

            if ($locked->status !== 'EXTERNAL') {
                throw new WorkOrderException('Only a finalized External Work Order can be revised.');
            }

            return $this->transitions->transition($locked, 'DRAFT');
        });
    }

    /** Cancel: EXTERNAL -> CANCELLED, reason mandatory. Separate from the generic internal cancel action. */
    public function cancel(WorkOrder $workOrder, string $reason): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $reason) {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);

            if ($locked->status !== 'EXTERNAL') {
                throw new WorkOrderException('Only a finalized External Work Order can be cancelled through this action.');
            }

            return $this->transitions->transition($locked, 'CANCELLED', ['cancellation_reason' => $reason]);
        });
    }
}
