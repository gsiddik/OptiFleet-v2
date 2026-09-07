<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\MaintenanceRequest\Services\MaintenanceRequestService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Support\Facades\DB;

class WorkOrderService
{
    public function __construct(
        private readonly WorkOrderNumberService $numbers,
        private readonly WorkOrderTransitionService $transitions,
        private readonly MaintenanceRequestService $requests,
    ) {}

    public function create(Vehicle $vehicle, array $attributes, ?string $createdByUserId = null): WorkOrder
    {
        return DB::transaction(function () use ($vehicle, $attributes, $createdByUserId) {
            $workOrder = WorkOrder::query()->create(array_merge($attributes, [
                'wo_number' => $this->numbers->generate(),
                'tenant_id' => $vehicle->tenant_id,
                'branch_id' => $attributes['branch_id'] ?? $vehicle->branch_id,
                'workshop_id' => $attributes['workshop_id'] ?? $vehicle->default_workshop_id,
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

    public function submitToQc(WorkOrder $workOrder): WorkOrder
    {
        return $this->transitions->transition($workOrder, 'QC_PENDING');
    }

    public function rework(WorkOrder $workOrder): WorkOrder
    {
        return $this->transitions->transition($workOrder, 'REWORK');
    }

    public function complete(WorkOrder $workOrder): WorkOrder
    {
        return $this->transitions->transition($workOrder, 'COMPLETED');
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
