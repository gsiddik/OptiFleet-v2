<?php

namespace App\Domain\MaintenanceRequest\Services;

use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\Notification\Services\NotificationDispatchService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Support\Facades\DB;

/**
 * Section 19/25: DRAFT -> SUBMITTED -> UNDER_REVIEW -> APPROVED ->
 * WORK_ORDER_CREATED, with REJECTED / CANCELLED side branches. Transition
 * validity is delegated to the workflow engine (against the platform-default
 * "maintenance_request" workflow) instead of a hardcoded array; the
 * request's own workflow_configuration_version_id, pinned at creation, is
 * what every future transition attempt is checked against.
 *
 * NEED_INFORMATION — LEGACY, NO NEW TRANSITIONS. Retired at the application
 * level only: the seeded workflow no longer allows any NEW transition into
 * it (see WorkflowDefaultsSeeder), so this branch is unreachable for new
 * requests. Any pre-existing record left in NEED_INFORMATION remains
 * readable/displayable and keeps a path out via its existing
 * NEED_INFORMATION -> UNDER_REVIEW / CANCELLED transitions — do not remove
 * those, and do not bulk-remap existing rows without an approved migration.
 */
class MaintenanceRequestService
{
    private const RESOURCE_TYPE = 'maintenance_request';

    public function __construct(
        private readonly MaintenanceRequestNumberService $numbers,
        private readonly WorkflowEngine $workflow,
        private readonly NotificationDispatchService $notifications,
    ) {}

    public function create(Vehicle $vehicle, array $attributes, ?string $requestedByUserId = null): MaintenanceRequest
    {
        return DB::transaction(function () use ($vehicle, $attributes, $requestedByUserId) {
            $branchId = $attributes['branch_id'] ?? $vehicle->branch_id;
            $number = $this->numbers->generate($vehicle->tenant_id, $branchId);
            $workflowVersion = $this->workflow->resolveEffective(self::RESOURCE_TYPE, $vehicle->tenant_id, $branchId);

            return MaintenanceRequest::query()->create(array_merge($attributes, [
                'request_number' => $number['document_number'],
                'numbering_configuration_version_id' => $number['configuration_version_id'],
                'workflow_configuration_version_id' => $workflowVersion?->id,
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

            $version = $this->workflow->resolvePinnedOrEffective($request->workflow_configuration_version_id, self::RESOURCE_TYPE, $request->tenant_id, $request->branch_id);
            if (! $this->workflow->isTransitionAllowedForVersion($version, $request->status, $to)) {
                throw new MaintenanceRequestException("Cannot transition maintenance request from {$request->status} to {$to}.");
            }

            $attributes = ['status' => $to];
            if (in_array($to, ['APPROVED', 'REJECTED'], true)) {
                $attributes['reviewed_by'] = $actorUserId;
                $attributes['reviewed_at'] = now();
                $attributes['review_note'] = $note;
            }
            if ($to === 'CANCELLED') {
                $attributes['cancellation_reason'] = $note;
            }

            $request->update($attributes);

            if ($to === 'SUBMITTED') {
                $vehicle = Vehicle::query()->find($request->vehicle_id);
                $this->notifications->dispatchEvent('maintenance_request.submitted', $request->tenant_id, [
                    'request' => ['number' => $request->request_number, 'priority' => $request->priority, 'complaint' => $request->complaint],
                    'vehicle' => ['registration_number' => $vehicle?->registration_number],
                    'branch_id' => $request->branch_id,
                    'workshop_id' => $request->workshop_id,
                    'requester_user_id' => $request->requested_by,
                ], self::RESOURCE_TYPE, $request->id);
            }

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
