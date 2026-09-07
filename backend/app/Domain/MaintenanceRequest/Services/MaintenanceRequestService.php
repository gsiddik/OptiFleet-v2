<?php

namespace App\Domain\MaintenanceRequest\Services;

use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Support\Facades\DB;

/**
 * Section 19: DRAFT -> SUBMITTED -> UNDER_REVIEW -> APPROVED ->
 * WORK_ORDER_CREATED, with REJECTED / NEED_INFORMATION / CANCELLED side
 * branches. Built as a small, swappable transition table (Section 19's
 * "structure so a workflow engine can be substituted later") rather than
 * status writes scattered across controllers.
 */
class MaintenanceRequestService
{
    private const TRANSITIONS = [
        'DRAFT' => ['SUBMITTED', 'CANCELLED'],
        'SUBMITTED' => ['UNDER_REVIEW', 'CANCELLED'],
        'UNDER_REVIEW' => ['APPROVED', 'REJECTED', 'NEED_INFORMATION', 'CANCELLED'],
        'NEED_INFORMATION' => ['UNDER_REVIEW', 'CANCELLED'],
        'APPROVED' => ['WORK_ORDER_CREATED', 'CANCELLED'],
    ];

    public function __construct(private readonly MaintenanceRequestNumberService $numbers) {}

    public function create(Vehicle $vehicle, array $attributes, ?string $requestedByUserId = null): MaintenanceRequest
    {
        return DB::transaction(function () use ($vehicle, $attributes, $requestedByUserId) {
            $branchId = $attributes['branch_id'] ?? $vehicle->branch_id;
            $number = $this->numbers->generate($vehicle->tenant_id, $branchId);

            return MaintenanceRequest::query()->create(array_merge($attributes, [
                'request_number' => $number['document_number'],
                'numbering_configuration_version_id' => $number['configuration_version_id'],
                'tenant_id' => $vehicle->tenant_id,
                'branch_id' => $branchId,
                'vehicle_id' => $vehicle->id,
                'requested_by' => $requestedByUserId,
                'status' => $attributes['status'] ?? 'DRAFT',
            ]));
        });
    }

    public function transition(MaintenanceRequest $request, string $to, ?string $actorUserId = null, ?string $note = null): MaintenanceRequest
    {
        return DB::transaction(function () use ($request, $to, $actorUserId, $note) {
            $request = MaintenanceRequest::query()->lockForUpdate()->findOrFail($request->id);

            $allowed = self::TRANSITIONS[$request->status] ?? [];
            if (! in_array($to, $allowed, true)) {
                throw new MaintenanceRequestException("Cannot transition maintenance request from {$request->status} to {$to}.");
            }

            $attributes = ['status' => $to];
            if (in_array($to, ['APPROVED', 'REJECTED', 'NEED_INFORMATION'], true)) {
                $attributes['reviewed_by'] = $actorUserId;
                $attributes['reviewed_at'] = now();
                $attributes['review_note'] = $note;
            }

            $request->update($attributes);

            return $request->fresh();
        });
    }

    /**
     * Marks the request converted once a Work Order has been created from
     * it — called by WorkOrderService, never sets WORK_ORDER_CREATED any
     * other way, so a request can only ever back one Work Order.
     */
    public function markConverted(MaintenanceRequest $request, string $workOrderId): MaintenanceRequest
    {
        return DB::transaction(function () use ($request, $workOrderId) {
            $request = MaintenanceRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($request->status !== 'APPROVED') {
                throw new MaintenanceRequestException('Only an approved maintenance request can be converted to a Work Order.');
            }
            if ($request->work_order_id) {
                throw new MaintenanceRequestException('This maintenance request has already been converted to a Work Order.');
            }

            $request->update(['status' => 'WORK_ORDER_CREATED', 'work_order_id' => $workOrderId]);

            return $request->fresh();
        });
    }
}
