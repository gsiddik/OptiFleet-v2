<?php

namespace App\Domain\Vehicle\Services;

use App\Domain\Configuration\Services\DocumentNumberingService;
use App\Domain\Shared\Support\Messages;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleAssignment;
use App\Domain\Vehicle\Models\VehicleTransfer;
use App\Domain\Workflow\Services\WorkflowEngine;
use Illuminate\Support\Facades\DB;

/**
 * Section 6/25: DRAFT -> REQUESTED -> APPROVED -> IN_TRANSIT -> RECEIVED ->
 * COMPLETED, with REJECTED/CANCELLED as terminal side branches. Transition
 * validity now goes through the Phase 5 workflow engine, checked against
 * the version pinned on the transfer at creation. The vehicle's active
 * branch/workshop is only updated on COMPLETED — every intermediate state
 * is purely a transfer-record concern, never mutates the vehicle itself,
 * so an abandoned or rejected transfer leaves the vehicle exactly where it
 * was.
 */
class VehicleTransferService
{
    private const RESOURCE_TYPE = 'vehicle_transfer';

    public function __construct(
        private readonly DocumentNumberingService $numbers,
        private readonly WorkflowEngine $workflow,
    ) {}

    public function createDraft(Vehicle $vehicle, array $attributes, string $requestedByUserId): VehicleTransfer
    {
        if ($vehicle->transfers()->whereNotIn('status', ['COMPLETED', 'REJECTED', 'CANCELLED'])->exists()) {
            throw new VehicleException('This vehicle already has an in-progress transfer.');
        }

        return DB::transaction(function () use ($vehicle, $attributes, $requestedByUserId) {
            $number = $this->numbers->generate('vehicle_transfer', $vehicle->tenant_id, $vehicle->branch_id, $vehicle->default_workshop_id);
            $workflowVersion = $this->workflow->resolveEffective(self::RESOURCE_TYPE, $vehicle->tenant_id, $vehicle->branch_id, $vehicle->default_workshop_id);

            return VehicleTransfer::query()->create(array_merge($attributes, [
                'tenant_id' => $vehicle->tenant_id,
                'transfer_number' => $number['document_number'],
                'numbering_configuration_version_id' => $number['configuration_version_id'],
                'workflow_configuration_version_id' => $workflowVersion?->id,
                'vehicle_id' => $vehicle->id,
                'from_branch_id' => $vehicle->branch_id,
                'from_workshop_id' => $vehicle->default_workshop_id,
                'status' => 'DRAFT',
                'requested_by' => $requestedByUserId,
            ]));
        });
    }

    public function transition(VehicleTransfer $transfer, string $to, ?string $actorUserId = null, ?string $note = null): VehicleTransfer
    {
        return DB::transaction(function () use ($transfer, $to, $actorUserId, $note) {
            $transfer = VehicleTransfer::query()->lockForUpdate()->findOrFail($transfer->id);

            $version = $this->workflow->resolvePinnedOrEffective($transfer->workflow_configuration_version_id, self::RESOURCE_TYPE, $transfer->tenant_id, $transfer->from_branch_id, $transfer->from_workshop_id);
            if (! $this->workflow->isTransitionAllowedForVersion($version, $transfer->status, $to)) {
                throw new VehicleException("Cannot transition transfer from {$transfer->status} to {$to}.");
            }

            $timestamps = match ($to) {
                'REQUESTED' => ['requested_at' => now()],
                'APPROVED' => ['approved_at' => now(), 'approved_by' => $actorUserId],
                'IN_TRANSIT' => ['dispatched_at' => now()],
                'RECEIVED' => ['received_at' => now()],
                'COMPLETED' => ['completed_at' => now()],
                default => [],
            };

            $transfer->update(array_merge($timestamps, [
                'status' => $to,
                'notes' => $note ?? $transfer->notes,
            ]));

            if ($to === 'COMPLETED') {
                $this->applyToVehicle($transfer);
            }

            return $transfer->fresh();
        });
    }

    private function applyToVehicle(VehicleTransfer $transfer): void
    {
        $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($transfer->vehicle_id);

        $today = now()->toDateString();
        VehicleAssignment::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereNull('effective_until')
            ->update(['effective_until' => $today]);

        VehicleAssignment::query()->create([
            'tenant_id' => $vehicle->tenant_id,
            'vehicle_id' => $vehicle->id,
            'from_branch_id' => $transfer->from_branch_id,
            'to_branch_id' => $transfer->to_branch_id,
            'from_workshop_id' => $transfer->from_workshop_id,
            'to_workshop_id' => $transfer->to_workshop_id,
            'effective_from' => $today,
            'assigned_by' => $transfer->approved_by,
            'notes' => Messages::text('vehicle.notes.vehicleTransfer', ['transferId' => $transfer->id]),
        ]);

        $vehicle->update([
            'branch_id' => $transfer->to_branch_id,
            'default_workshop_id' => $transfer->to_workshop_id,
        ]);
    }
}
