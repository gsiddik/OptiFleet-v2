<?php

namespace App\Domain\QualityControl\Services;

use App\Domain\QualityControl\Models\QcFinding;
use App\Domain\QualityControl\Models\QcInspection;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Services\WorkOrderService;
use App\Domain\Workshop\Models\Worker;
use Illuminate\Support\Facades\DB;

/**
 * Section 38: QC_PENDING -> QC_STARTED -> PASS -> COMPLETED, or
 * QC_STARTED -> FAIL -> REWORK (which hands the Work Order back to
 * WorkOrderService for the REWORK/IN_PROGRESS/QC_PENDING loop — QC never
 * mutates WO status directly beyond that one handoff). Section 36:
 * "the mechanic who performed the work is not automatically the QC
 * approver" is enforced structurally, not by role name — a worker who has
 * an active (non-unassigned) mechanic assignment on this WO cannot start
 * its QC inspection.
 */
class QualityControlService
{
    public function __construct(private readonly WorkOrderService $workOrders) {}

    public function start(WorkOrder $workOrder, ?string $inspectorWorkerId, ?string $actingUserId = null): QcInspection
    {
        if ($workOrder->status !== 'QC_PENDING') {
            throw new QualityControlException('QC can only start once the Work Order is pending QC.');
        }

        if ($inspectorWorkerId) {
            $performedWork = $workOrder->mechanicAssignments()
                ->where('worker_id', $inspectorWorkerId)
                ->whereNull('unassigned_at')
                ->exists();
            if ($performedWork) {
                throw new QualityControlException('The worker who performed this Work Order cannot also perform its QC.');
            }
        }

        return QcInspection::query()->create([
            'tenant_id' => $workOrder->tenant_id,
            'work_order_id' => $workOrder->id,
            'inspector_worker_id' => $inspectorWorkerId,
            'status' => 'QC_STARTED',
            'started_at' => now(),
        ]);
    }

    public function addFinding(QcInspection $inspection, array $attributes): QcFinding
    {
        return QcFinding::query()->create(array_merge($attributes, ['qc_inspection_id' => $inspection->id, 'resolved' => false]));
    }

    /** G-04: previously `resolved` had no API to ever set it true — every QC finding stayed unresolved forever. */
    public function resolveFinding(QcFinding $finding, ?string $userId): QcFinding
    {
        return DB::transaction(function () use ($finding, $userId) {
            $locked = QcFinding::query()->lockForUpdate()->findOrFail($finding->id);
            if ($locked->resolved) {
                throw new QualityControlException('This finding is already resolved.');
            }
            $locked->update(['resolved' => true, 'resolved_by' => $userId, 'resolved_at' => now()]);

            return $locked->fresh();
        });
    }

    public function pass(QcInspection $inspection): QcInspection
    {
        if ($inspection->status !== 'QC_STARTED') {
            throw new QualityControlException('Only a started QC inspection can be passed.');
        }
        $inspection->update(['status' => 'PASS']);

        return $inspection->fresh();
    }

    public function fail(QcInspection $inspection, ?string $note = null): QcInspection
    {
        if ($inspection->status !== 'QC_STARTED') {
            throw new QualityControlException('Only a started QC inspection can be failed.');
        }

        return DB::transaction(function () use ($inspection, $note) {
            $inspection->update(['status' => 'FAIL', 'notes' => $note ?? $inspection->notes]);

            $workOrder = WorkOrder::query()->findOrFail($inspection->work_order_id);
            $this->workOrders->rework($workOrder);

            return $inspection->fresh();
        });
    }

    public function complete(QcInspection $inspection): QcInspection
    {
        if ($inspection->status !== 'PASS') {
            throw new QualityControlException('Only a passed QC inspection can be completed.');
        }
        $inspection->update(['status' => 'COMPLETED', 'completed_at' => now()]);

        return $inspection->fresh();
    }
}
