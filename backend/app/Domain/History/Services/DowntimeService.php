<?php

namespace App\Domain\History\Services;

use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\QualityControl\Models\QcInspection;
use App\Domain\VehicleRelease\Models\VehicleRelease;
use App\Domain\WorkOrder\Models\WorkOrder;
use Carbon\Carbon;

/**
 * Section 41: derives downtime milestones from timestamps that already
 * exist on their owning records (Breakdown, WorkOrder, QcInspection,
 * VehicleRelease) rather than a redundant tracking table, so the same
 * numbers stay reusable once a MongoDB analytics phase reads them.
 */
class DowntimeService
{
    public function forWorkOrder(WorkOrder $workOrder): array
    {
        $breakdown = $workOrder->breakdown_id ? Breakdown::query()->find($workOrder->breakdown_id) : null;
        $qcPass = QcInspection::query()->where('work_order_id', $workOrder->id)
            ->whereIn('status', ['PASS', 'COMPLETED'])->oldest('completed_at')->first();
        $release = VehicleRelease::query()->where('work_order_id', $workOrder->id)->first();

        $breakdownReportedAt = $breakdown?->reported_at;
        $vehicleOffRoadAt = $breakdown?->downtime_start_at ?? $workOrder->started_at;
        $maintenanceStartedAt = $workOrder->started_at;
        $repairCompletedAt = $workOrder->completed_at;
        $qcPassedAt = $qcPass?->completed_at;
        $vehicleReleasedAt = $release?->released_at;

        return [
            'breakdown_reported_at' => $breakdownReportedAt,
            'vehicle_off_road_at' => $vehicleOffRoadAt,
            'maintenance_started_at' => $maintenanceStartedAt,
            'repair_completed_at' => $repairCompletedAt,
            'qc_passed_at' => $qcPassedAt,
            'vehicle_released_at' => $vehicleReleasedAt,
            'response_time_minutes' => $this->minutesBetween($vehicleOffRoadAt, $maintenanceStartedAt),
            'repair_time_minutes' => $this->minutesBetween($maintenanceStartedAt, $repairCompletedAt),
            'waiting_time_minutes' => $this->minutesBetween($repairCompletedAt, $qcPassedAt),
            'total_downtime_minutes' => $this->minutesBetween($vehicleOffRoadAt, $vehicleReleasedAt),
        ];
    }

    private function minutesBetween(?Carbon $from, ?Carbon $to): ?int
    {
        if (! $from || ! $to || $to->lt($from)) {
            return null;
        }

        return (int) $from->diffInMinutes($to);
    }
}
