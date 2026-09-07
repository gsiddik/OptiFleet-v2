<?php

namespace App\Domain\Breakdown\Services;

use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\MaintenanceRequest\Services\MaintenanceRequestService;
use App\Domain\Notification\Services\NotificationDispatchService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Support\Facades\DB;

/**
 * Section 20/25: REPORTED -> VERIFIED -> ASSESSED -> REPAIR_REQUIRED ->
 * WORK_ORDER_CREATED -> RESOLVED, now validated through the Phase 5
 * workflow engine against the version pinned at report() time. Reporting a
 * breakdown also flips the vehicle into BREAKDOWN status immediately
 * (Section 3) — the vehicle is off-road from the moment it's reported, not
 * from some later review step.
 */
class BreakdownService
{
    private const RESOURCE_TYPE = 'breakdown';

    public function __construct(
        private readonly MaintenanceRequestService $requests,
        private readonly WorkflowEngine $workflow,
        private readonly NotificationDispatchService $notifications,
    ) {}

    public function report(Vehicle $vehicle, array $attributes, ?string $reportedByUserId = null): Breakdown
    {
        return DB::transaction(function () use ($vehicle, $attributes, $reportedByUserId) {
            $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id);
            $workflowVersion = $this->workflow->resolveEffective(self::RESOURCE_TYPE, $vehicle->tenant_id, $vehicle->branch_id);

            $breakdown = Breakdown::query()->create(array_merge($attributes, [
                'tenant_id' => $vehicle->tenant_id,
                'workflow_configuration_version_id' => $workflowVersion?->id,
                'branch_id' => $vehicle->branch_id,
                'vehicle_id' => $vehicle->id,
                'reported_by' => $reportedByUserId,
                'reported_at' => now(),
                'downtime_start_at' => now(),
                'status' => 'REPORTED',
            ]));

            $vehicle->update(['status' => 'BREAKDOWN', 'operational_status' => 'ON_HOLD']);

            $this->notifications->dispatchEvent('breakdown.reported', $vehicle->tenant_id, [
                'breakdown' => ['severity' => $breakdown->severity, 'location' => $breakdown->location, 'description' => $breakdown->description],
                'vehicle' => ['registration_number' => $vehicle->registration_number],
                'branch_id' => $vehicle->branch_id,
                'requester_user_id' => $reportedByUserId,
            ], self::RESOURCE_TYPE, $breakdown->id);

            return $breakdown;
        });
    }

    public function transition(Breakdown $breakdown, string $to, ?string $note = null): Breakdown
    {
        return DB::transaction(function () use ($breakdown, $to, $note) {
            $breakdown = Breakdown::query()->lockForUpdate()->findOrFail($breakdown->id);

            $version = $this->workflow->resolvePinnedOrEffective($breakdown->workflow_configuration_version_id, self::RESOURCE_TYPE, $breakdown->tenant_id, $breakdown->branch_id);
            if (! $this->workflow->isTransitionAllowedForVersion($version, $breakdown->status, $to)) {
                throw new BreakdownException("Cannot transition breakdown from {$breakdown->status} to {$to}.");
            }

            $attributes = ['status' => $to];
            if ($note !== null) {
                $attributes['response_notes'] = $note;
            }
            if ($to === 'RESOLVED') {
                $attributes['resolved_at'] = now();
            }

            $breakdown->update($attributes);

            if ($to === 'RESOLVED') {
                Vehicle::query()->where('id', $breakdown->vehicle_id)->update(['status' => 'ACTIVE', 'operational_status' => 'AVAILABLE']);
            }

            return $breakdown->fresh();
        });
    }

    public function convertToMaintenanceRequest(Breakdown $breakdown, array $attributes, ?string $requestedByUserId = null)
    {
        if ($breakdown->status !== 'REPAIR_REQUIRED') {
            throw new BreakdownException('Only a breakdown assessed as repair-required can be converted to a maintenance request.');
        }

        return DB::transaction(function () use ($breakdown, $attributes, $requestedByUserId) {
            $vehicle = Vehicle::query()->findOrFail($breakdown->vehicle_id);

            $request = $this->requests->create($vehicle, array_merge($attributes, [
                'source_type' => 'BREAKDOWN',
                'source_breakdown_id' => $breakdown->id,
                'priority' => $attributes['priority'] ?? ($breakdown->severity === 'IMMOBILIZED' ? 'URGENT' : 'HIGH'),
                'complaint' => $attributes['complaint'] ?? $breakdown->description,
                'status' => 'SUBMITTED',
            ]), $requestedByUserId);

            $breakdown->update(['maintenance_request_id' => $request->id]);

            return $request;
        });
    }
}
