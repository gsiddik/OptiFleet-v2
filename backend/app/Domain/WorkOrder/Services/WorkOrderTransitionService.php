<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Tire\Services\TireOperationExecutionService;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

/**
 * Section 24/25: a single reusable transition entry point rather than
 * status writes scattered across controllers. Transition validity is
 * delegated to the Phase 5 workflow engine, checked against exactly the
 * "work_order" workflow version this WO was created under (Section 25 —
 * an in-flight WO never has its rules silently changed by a later
 * republish) — this graph includes the intentional QC_PENDING -> REWORK
 * -> IN_PROGRESS loop. Every call row-locks the WO first (Section 51/52):
 * the status guard is re-checked against the locked row, never the
 * caller's possibly-stale model instance, so two concurrent transitions on
 * the same WO can't both win.
 */
class WorkOrderTransitionService
{
    private const RESOURCE_TYPE = 'work_order';

    /** G-17/G-35: these targets are guarded against dangling parts/tire activity, never earlier transitions. */
    private const GUARDED_TARGETS = ['COMPLETED', 'CLOSED'];

    public function __construct(
        private readonly WorkflowEngine $workflow,
        private readonly WorkOrderClosureGuardService $closureGuard,
        private readonly TireOperationExecutionService $tireOperations,
    ) {}

    public function canTransition(WorkOrder $workOrder, string $to): bool
    {
        $version = $this->workflow->resolvePinnedOrEffective($workOrder->workflow_configuration_version_id, self::RESOURCE_TYPE, $workOrder->tenant_id, $workOrder->branch_id, $workOrder->workshop_id);

        return $this->workflow->isTransitionAllowedForVersion($version, $workOrder->status, $to);
    }

    public function transition(WorkOrder $workOrder, string $to, array $extra = []): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $to, $extra) {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);

            if (! $this->canTransition($locked, $to)) {
                throw new WorkOrderException("Cannot transition Work Order from {$locked->status} to {$to}.");
            }

            if (in_array($to, self::GUARDED_TARGETS, true)) {
                $this->closureGuard->assertClosable($locked);
            }
            // A Tire Operation's rotation/inspection happens with the work: applied when the Work
            // Order completes (a failure aborts the transition); cancelled/rejected releases it.
            if ($to === 'COMPLETED') {
                $this->tireOperations->applyOnWorkOrderCompleted($locked, auth()->id());
            }

            $timestamps = match ($to) {
                'IN_PROGRESS' => ['started_at' => $locked->started_at ?? now()],
                'COMPLETED' => ['completed_at' => now()],
                'CLOSED' => ['closed_at' => now()],
                default => [],
            };

            $locked->update(array_merge($timestamps, $extra, ['status' => $to]));

            if (in_array($to, ['CANCELLED', 'REJECTED'], true)) {
                $this->tireOperations->releaseOnWorkOrderClosedWithoutWork($locked, auth()->id());
            }

            return $locked->fresh();
        });
    }
}
