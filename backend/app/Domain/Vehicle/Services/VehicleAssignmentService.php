<?php

namespace App\Domain\Vehicle\Services;

use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Models\VehicleAssignment;
use Illuminate\Support\Facades\DB;

/**
 * Direct branch/workshop assignment (Section 5) — an administrative
 * override, distinct from the approval-gated VehicleTransferService. Each
 * call closes the vehicle's currently-open assignment period (effective_
 * until = today) and opens a new one, so the assignment table is a full
 * history, never just an overwritten "current" pointer.
 */
class VehicleAssignmentService
{
    public function assign(Vehicle $vehicle, array $attributes, ?string $assignedByUserId = null): VehicleAssignment
    {
        return DB::transaction(function () use ($vehicle, $attributes, $assignedByUserId) {
            $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($vehicle->id);

            $toBranchId = $attributes['branch_id'] ?? $vehicle->branch_id;
            $toWorkshopId = array_key_exists('default_workshop_id', $attributes)
                ? $attributes['default_workshop_id']
                : $vehicle->default_workshop_id;

            $today = now()->toDateString();

            VehicleAssignment::query()
                ->where('vehicle_id', $vehicle->id)
                ->whereNull('effective_until')
                ->update(['effective_until' => $today]);

            $assignment = VehicleAssignment::query()->create([
                'tenant_id' => $vehicle->tenant_id,
                'vehicle_id' => $vehicle->id,
                'from_branch_id' => $vehicle->branch_id,
                'to_branch_id' => $toBranchId,
                'from_workshop_id' => $vehicle->default_workshop_id,
                'to_workshop_id' => $toWorkshopId,
                'effective_from' => $today,
                'assigned_by' => $assignedByUserId,
                'notes' => $attributes['notes'] ?? null,
            ]);

            $vehicle->update([
                'branch_id' => $toBranchId,
                'default_workshop_id' => $toWorkshopId,
            ]);

            return $assignment;
        });
    }
}
