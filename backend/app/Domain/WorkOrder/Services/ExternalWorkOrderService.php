<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderExternalInvoice;
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

            WorkOrderExternalInvoice::query()->firstOrCreate(
                ['work_order_id' => $updated->id],
                ['tenant_id' => $updated->tenant_id, 'branch_id' => $updated->branch_id, 'status' => 'NEW_EXTERNAL_WO']
            );

            return $updated;
        });
    }

    /**
     * Revise: EXTERNAL -> DRAFT, keeping External mode, WO number, and the last finalized
     * revision. Only allowed while the Invoice is still NEW_EXTERNAL_WO (before Deliver) — once
     * the physical Work Order + Work Authorization Letter have been handed to the external
     * workshop, editing Findings back in Draft would leave the printed documents out of sync
     * with what the workshop actually holds. The consolidated business document does not state
     * this restriction explicitly for Revise (only for Cancel); this is a deliberate,
     * disclosed judgment call pending business confirmation.
     */
    public function revise(WorkOrder $workOrder): WorkOrder
    {
        return DB::transaction(function () use ($workOrder) {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);

            if ($locked->status !== 'EXTERNAL') {
                throw new WorkOrderException('Only a finalized External Work Order can be revised.');
            }

            $invoice = WorkOrderExternalInvoice::query()->where('work_order_id', $locked->id)->first();
            if ($invoice && $invoice->status !== 'NEW_EXTERNAL_WO') {
                throw new WorkOrderException('This Work Order can no longer be revised — its External Invoice has already moved past New External WO.');
            }

            return $this->transitions->transition($locked, 'DRAFT');
        });
    }

    /**
     * Cancel: EXTERNAL -> CANCELLED, reason mandatory. Separate from the generic internal cancel
     * action. Synchronizes the linked External Invoice to CANCELLED in the same transaction,
     * unless it has already moved past a business-safe-to-cancel stage (see
     * WorkOrderExternalInvoice::CANCELLABLE_STATUSES / the action matrix), in which case the
     * whole cancel is rejected rather than leaving the two aggregates inconsistent.
     */
    public function cancel(WorkOrder $workOrder, string $reason, ?string $actorUserId = null): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $reason, $actorUserId) {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);

            if ($locked->status !== 'EXTERNAL') {
                throw new WorkOrderException('Only a finalized External Work Order can be cancelled through this action.');
            }

            $invoice = WorkOrderExternalInvoice::query()->where('work_order_id', $locked->id)->lockForUpdate()->first();
            if ($invoice && ! in_array($invoice->status, WorkOrderExternalInvoice::CANCELLABLE_STATUSES, true)) {
                throw new WorkOrderException("This Work Order's External Invoice is already {$invoice->status} and can no longer be cancelled.");
            }

            $updated = $this->transitions->transition($locked, 'CANCELLED', ['cancellation_reason' => $reason]);

            $invoice?->update([
                'status' => 'CANCELLED',
                'cancelled_by' => $actorUserId,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ]);

            return $updated;
        });
    }
}
