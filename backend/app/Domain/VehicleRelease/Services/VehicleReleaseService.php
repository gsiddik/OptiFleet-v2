<?php

namespace App\Domain\VehicleRelease\Services;

use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\VehicleRelease\Models\VehicleRelease;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Services\WorkOrderTransitionService;
use Illuminate\Support\Facades\DB;

/**
 * Section 39: a Work Order must be COMPLETED before its vehicle can be
 * released, and release is refused outright — not merely discouraged in
 * the UI — while any OTHER work order for the same vehicle is still open
 * (Section 61: frontend guards are UX only, this is the authoritative
 * check). One release per Work Order (DB unique constraint on
 * work_order_id closes the double-release race).
 */
class VehicleReleaseService
{
    public function __construct(private readonly WorkOrderTransitionService $transitions) {}

    private const BLOCKING_STATUSES = [
        'DRAFT', 'SUBMITTED', 'APPROVED', 'ASSIGNED', 'SCHEDULED', 'IN_PROGRESS',
        'ON_HOLD', 'WAITING_PART', 'QC_PENDING', 'REWORK', 'COMPLETED',
    ];

    public function release(WorkOrder $workOrder, array $attributes, ?string $releasedByUserId = null): VehicleRelease
    {
        if ($workOrder->status !== 'COMPLETED') {
            throw new VehicleReleaseException('Only a completed Work Order can release its vehicle.');
        }

        return DB::transaction(function () use ($workOrder, $attributes, $releasedByUserId) {
            $vehicle = Vehicle::query()->lockForUpdate()->findOrFail($workOrder->vehicle_id);

            $blockingWo = WorkOrder::query()
                ->where('vehicle_id', $vehicle->id)
                ->where('id', '!=', $workOrder->id)
                ->whereIn('status', self::BLOCKING_STATUSES)
                ->exists();

            if ($blockingWo) {
                throw new VehicleReleaseException('This vehicle has another active Work Order and cannot be released yet.');
            }

            try {
                $release = VehicleRelease::query()->create(array_merge($attributes, [
                    'tenant_id' => $workOrder->tenant_id,
                    'work_order_id' => $workOrder->id,
                    'vehicle_id' => $vehicle->id,
                    'released_at' => now(),
                    'released_by' => $releasedByUserId,
                ]));
            } catch (\Illuminate\Database\QueryException $e) {
                throw new VehicleReleaseException('This Work Order\'s vehicle has already been released.');
            }

            $this->transitions->transition($workOrder, 'CLOSED');
            $vehicle->update([
                'status' => 'ACTIVE',
                'operational_status' => 'AVAILABLE',
                'current_odometer' => max((float) $vehicle->current_odometer, (float) ($attributes['release_odometer'] ?? 0)),
            ]);

            return $release;
        });
    }
}
