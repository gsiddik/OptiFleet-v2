<?php

namespace App\Domain\History\Services;

use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\Inspection\Models\Inspection;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\QualityControl\Models\QcInspection;
use App\Domain\VehicleRelease\Models\VehicleRelease;
use App\Domain\WorkOrder\Models\WorkOrder;
use Illuminate\Support\Collection;

/**
 * Section 40: a queryable, vehicle-centric timeline built by tagging and
 * merging each domain's own existing records at read time — deliberately
 * not a separate history table duplicating transaction data (the section's
 * explicit guidance). Every source record already carries its own
 * timestamp and tenant scope; this just normalizes them into one sorted
 * feed.
 */
class HistoryService
{
    public function forVehicle(string $tenantId, string $vehicleId): Collection
    {
        $events = collect();

        Inspection::query()->where('tenant_id', $tenantId)->where('vehicle_id', $vehicleId)
            ->get(['id', 'status', 'inspection_type', 'submitted_at', 'created_at'])
            ->each(fn ($i) => $events->push([
                'type' => 'INSPECTION',
                'id' => $i->id,
                'at' => $i->submitted_at ?? $i->created_at,
                'summary' => "{$i->inspection_type} inspection — {$i->status}",
            ]));

        MaintenanceRequest::query()->where('tenant_id', $tenantId)->where('vehicle_id', $vehicleId)
            ->get(['id', 'request_number', 'status', 'created_at'])
            ->each(fn ($r) => $events->push([
                'type' => 'MAINTENANCE_REQUEST',
                'id' => $r->id,
                'at' => $r->created_at,
                'summary' => "Request {$r->request_number} — {$r->status}",
            ]));

        Breakdown::query()->where('tenant_id', $tenantId)->where('vehicle_id', $vehicleId)
            ->get(['id', 'severity', 'status', 'reported_at'])
            ->each(fn ($b) => $events->push([
                'type' => 'BREAKDOWN',
                'id' => $b->id,
                'at' => $b->reported_at,
                'summary' => "Breakdown ({$b->severity}) — {$b->status}",
            ]));

        $workOrders = WorkOrder::query()->where('tenant_id', $tenantId)->where('vehicle_id', $vehicleId)
            ->get(['id', 'wo_number', 'status', 'maintenance_type', 'created_at', 'completed_at']);

        $workOrders->each(fn ($wo) => $events->push([
            'type' => 'WORK_ORDER',
            'id' => $wo->id,
            'at' => $wo->created_at,
            'summary' => "Work Order {$wo->wo_number} ({$wo->maintenance_type}) — {$wo->status}",
        ]));

        $woIds = $workOrders->pluck('id');

        QcInspection::query()->whereIn('work_order_id', $woIds)
            ->get(['id', 'work_order_id', 'status', 'completed_at', 'created_at'])
            ->each(fn ($qc) => $events->push([
                'type' => 'QC',
                'id' => $qc->id,
                'at' => $qc->completed_at ?? $qc->created_at,
                'summary' => "QC inspection — {$qc->status}",
                'work_order_id' => $qc->work_order_id,
            ]));

        VehicleRelease::query()->where('vehicle_id', $vehicleId)
            ->get(['id', 'work_order_id', 'released_at'])
            ->each(fn ($rel) => $events->push([
                'type' => 'VEHICLE_RELEASE',
                'id' => $rel->id,
                'at' => $rel->released_at,
                'summary' => 'Vehicle released',
                'work_order_id' => $rel->work_order_id,
            ]));

        return $events->sortByDesc('at')->values();
    }
}
