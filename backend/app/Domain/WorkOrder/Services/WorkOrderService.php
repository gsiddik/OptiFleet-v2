<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\MaintenancePolicy\Models\MaintenanceSchedule;
use App\Domain\MaintenancePolicy\Services\MaintenanceScheduleService;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\MaintenanceRequest\Services\MaintenanceRequestService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Workflow\Services\WorkflowEngine;
use App\Domain\WorkOrder\Models\WorkOrder;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

class WorkOrderService
{
    public function __construct(
        private readonly WorkOrderNumberService $numbers,
        private readonly WorkOrderTransitionService $transitions,
        private readonly MaintenanceRequestService $requests,
        private readonly MaintenanceScheduleService $schedules,
        private readonly WorkflowEngine $workflow,
    ) {}

    public function create(Vehicle $vehicle, array $attributes, ?string $createdByUserId = null): WorkOrder
    {
        return DB::transaction(function () use ($vehicle, $attributes, $createdByUserId) {
            $branchId = $attributes['branch_id'] ?? $vehicle->branch_id;
            $workshopId = $attributes['workshop_id'] ?? $vehicle->default_workshop_id;
            $number = $this->numbers->generate($vehicle->tenant_id, $branchId, $workshopId);
            $workflowVersion = $this->workflow->resolveEffective('work_order', $vehicle->tenant_id, $branchId, $workshopId);

            $workOrder = WorkOrder::query()->create(array_merge($attributes, [
                'wo_number' => $number['document_number'],
                'numbering_configuration_version_id' => $number['configuration_version_id'],
                'workflow_configuration_version_id' => $workflowVersion?->id,
                'tenant_id' => $vehicle->tenant_id,
                'branch_id' => $branchId,
                'workshop_id' => $workshopId,
                'vehicle_id' => $vehicle->id,
                'current_odometer' => $attributes['current_odometer'] ?? $vehicle->current_odometer,
                'status' => 'DRAFT',
                'created_by' => $createdByUserId,
            ]));

            if (! empty($attributes['maintenance_request_id'])) {
                $request = MaintenanceRequest::query()->findOrFail($attributes['maintenance_request_id']);
                $this->requests->markConverted($request, $workOrder->id);
            }

            if (! empty($attributes['breakdown_id'])) {
                Breakdown::query()->where('id', $attributes['breakdown_id'])->update([
                    'status' => 'WORK_ORDER_CREATED',
                    'work_order_id' => $workOrder->id,
                ]);
            }

            return $workOrder->fresh();
        });
    }

    public function fromMaintenanceRequest(MaintenanceRequest $request, array $attributes, ?string $createdByUserId = null): WorkOrder
    {
        if ($request->status !== 'APPROVED') {
            throw new WorkOrderException('Only an approved maintenance request can be converted to a Work Order.');
        }
        if ($request->work_order_id) {
            throw new WorkOrderException('This maintenance request has already been converted to a Work Order.');
        }

        $vehicle = Vehicle::query()->findOrFail($request->vehicle_id);

        return $this->create($vehicle, array_merge($attributes, [
            'branch_id' => $request->branch_id,
            'workshop_id' => $request->workshop_id ?? $vehicle->default_workshop_id,
            'maintenance_request_id' => $request->id,
            'maintenance_type' => $attributes['maintenance_type'] ?? 'CORRECTIVE',
            'priority' => $attributes['priority'] ?? $request->priority,
            'complaint' => $attributes['complaint'] ?? $request->complaint,
        ]), $createdByUserId);
    }

    /**
     * G-01: previously a due/overdue MaintenanceSchedule sat inert — no
     * code path ever converted one into a Work Order (MaintenanceScheduleService
     * ::markCompleted() existed but was never called from anywhere). Mirrors
     * fromMaintenanceRequest()'s shape: a schedule not yet due for conversion,
     * or one that already has an open (non-CANCELLED/CLOSED) Work Order, is
     * rejected outright rather than silently creating a duplicate.
     */
    public function fromMaintenanceSchedule(MaintenanceSchedule $schedule, array $attributes, ?string $createdByUserId = null): WorkOrder
    {
        if (! in_array($schedule->status, ['DUE_SOON', 'DUE', 'OVERDUE'], true)) {
            throw new WorkOrderException("This maintenance schedule is {$schedule->status} and is not yet ready to convert to a Work Order.");
        }
        $hasOpenWorkOrder = WorkOrder::query()
            ->where('maintenance_schedule_id', $schedule->id)
            ->whereNotIn('status', ['CANCELLED', 'CLOSED'])
            ->exists();
        if ($hasOpenWorkOrder) {
            throw new WorkOrderException('This maintenance schedule already has an open Work Order.');
        }

        $vehicle = Vehicle::query()->findOrFail($schedule->vehicle_id);

        return $this->create($vehicle, array_merge($attributes, [
            'maintenance_schedule_id' => $schedule->id,
            'maintenance_type' => $attributes['maintenance_type'] ?? 'PREVENTIVE',
        ]), $createdByUserId);
    }

    /**
     * G-02: work_orders previously had no cost-estimate field at all.
     * Amounts are decimal(16,4), matching work_order_planned_parts' own
     * cost columns — computed with BigDecimal (never native float
     * arithmetic), rounded half-up. Only meaningful before execution
     * starts: once a WO is IN_PROGRESS or further, actual part costs are
     * already being recorded at issue time, so re-estimating is blocked.
     */
    public function estimate(WorkOrder $workOrder, ?string $laborCost, ?string $partsCost, ?string $userId): WorkOrder
    {
        return DB::transaction(function () use ($workOrder, $laborCost, $partsCost, $userId) {
            $locked = WorkOrder::query()->lockForUpdate()->findOrFail($workOrder->id);
            if (! in_array($locked->status, ['DRAFT', 'SUBMITTED', 'APPROVED', 'ASSIGNED', 'SCHEDULED'], true)) {
                throw new WorkOrderException("Cannot estimate cost once a Work Order is {$locked->status} — actual costs are tracked from that point on.");
            }

            $total = null;
            if ($laborCost !== null || $partsCost !== null) {
                $total = BigDecimal::of($laborCost ?? '0')
                    ->plus(BigDecimal::of($partsCost ?? '0'))
                    ->toScale(4, RoundingMode::HALF_UP);
            }

            $locked->update([
                'estimated_labor_cost' => $laborCost,
                'estimated_parts_cost' => $partsCost,
                'estimated_total_cost' => $total !== null ? (string) $total : null,
                'estimated_by' => $userId,
                'estimated_at' => now(),
            ]);

            return $locked->fresh();
        });
    }

    public function submit(WorkOrder $workOrder): WorkOrder
    {
        return $this->transitions->transition($workOrder, 'SUBMITTED');
    }

    public function approve(WorkOrder $workOrder): WorkOrder
    {
        return $this->transitions->transition($workOrder, 'APPROVED');
    }

    public function reject(WorkOrder $workOrder): WorkOrder
    {
        return $this->transitions->transition($workOrder, 'REJECTED');
    }

    public function assign(WorkOrder $workOrder): WorkOrder
    {
        return $this->transitions->transition($workOrder, 'ASSIGNED');
    }

    public function schedule(WorkOrder $workOrder, ?string $workspaceId = null, ?\DateTimeInterface $targetStartAt = null, ?\DateTimeInterface $targetCompletionAt = null): WorkOrder
    {
        return $this->transitions->transition($workOrder, 'SCHEDULED', array_filter([
            'workspace_id' => $workspaceId,
            'target_start_at' => $targetStartAt,
            'target_completion_at' => $targetCompletionAt,
        ], fn ($v) => $v !== null));
    }

    public function start(WorkOrder $workOrder): WorkOrder
    {
        $updated = $this->transitions->transition($workOrder, 'IN_PROGRESS');

        Vehicle::query()->where('id', $updated->vehicle_id)->update([
            'status' => 'IN_MAINTENANCE',
            'operational_status' => 'ON_HOLD',
        ]);

        return $updated;
    }

    public function hold(WorkOrder $workOrder): WorkOrder
    {
        return $this->transitions->transition($workOrder, 'ON_HOLD');
    }

    public function resume(WorkOrder $workOrder): WorkOrder
    {
        return $this->transitions->transition($workOrder, 'IN_PROGRESS');
    }

    public function waitForPart(WorkOrder $workOrder): WorkOrder
    {
        return $this->transitions->transition($workOrder, 'WAITING_PART');
    }

    /** EXTERNAL: work is being carried out by an external workshop (reachable from SCHEDULED or IN_PROGRESS). */
    public function sendExternal(WorkOrder $workOrder): WorkOrder
    {
        return $this->transitions->transition($workOrder, 'EXTERNAL');
    }

    public function submitToQc(WorkOrder $workOrder): WorkOrder
    {
        return $this->transitions->transition($workOrder, 'QC_PENDING');
    }

    public function rework(WorkOrder $workOrder): WorkOrder
    {
        return $this->transitions->transition($workOrder, 'REWORK');
    }

    /**
     * G-03: previously completing a WO only stamped completed_at — no
     * structured outcome was ever recorded. result_summary is optional
     * (not required) to avoid breaking every existing completion call
     * site/test across the codebase with a new mandatory field; if the
     * report intends this as mandatory, that is a follow-up product
     * decision, not assumed here.
     *
     * G-01: if this WO was converted from a MaintenanceSchedule, completing
     * it now actually closes the loop — the schedule is marked completed
     * and regenerated for its next cycle (previously dead code, never
     * invoked from anywhere).
     */
    public function complete(WorkOrder $workOrder, ?string $resultSummary = null, ?string $userId = null): WorkOrder
    {
        $updated = $this->transitions->transition($workOrder, 'COMPLETED', array_filter([
            'result_summary' => $resultSummary,
            'result_recorded_by' => $resultSummary !== null ? $userId : null,
            'result_recorded_at' => $resultSummary !== null ? now() : null,
        ], fn ($v) => $v !== null));

        if ($updated->maintenance_schedule_id) {
            $schedule = MaintenanceSchedule::query()->find($updated->maintenance_schedule_id);
            if ($schedule) {
                $this->schedules->markCompleted($schedule, (float) $updated->current_odometer, $updated->id);
            }
        }

        return $updated;
    }

    public function close(WorkOrder $workOrder): WorkOrder
    {
        return $this->transitions->transition($workOrder, 'CLOSED');
    }

    public function cancel(WorkOrder $workOrder): WorkOrder
    {
        return $this->transitions->transition($workOrder, 'CANCELLED');
    }
}
