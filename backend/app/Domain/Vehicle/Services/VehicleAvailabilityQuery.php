<?php

namespace App\Domain\Vehicle\Services;

use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Models\WorkOrder;
use Carbon\CarbonImmutable;

/**
 * Answers "can this vehicle take a trip in [from, to]?" for a transport system. Read only, tenant scoped by the caller's
 * TenantContext. A vehicle is available when it is operationally AVAILABLE now and no open work order is planned to have it in
 * the workshop during the window. The answer lists the reasons, so the caller can show them to a dispatcher.
 */
class VehicleAvailabilityQuery
{
    /** Work orders in these states no longer keep the vehicle in the workshop. */
    private const FINISHED = ['CANCELLED', 'CLOSED', 'COMPLETED'];

    /**
     * @return array<string, mixed>|null null when the tenant has no such vehicle
     */
    public function answer(?string $vehicleId, ?string $registrationNumber, CarbonImmutable $from, CarbonImmutable $to): ?array
    {
        $vehicle = Vehicle::query()
            ->when($vehicleId, fn ($q) => $q->whereKey($vehicleId))
            ->when(! $vehicleId, fn ($q) => $q->whereRaw('upper(registration_number) = ?', [mb_strtoupper((string) $registrationNumber)]))
            ->first();

        if (! $vehicle) {
            return null;
        }

        $windows = WorkOrder::query()
            ->where('vehicle_id', $vehicle->id)
            ->whereNotIn('status', self::FINISHED)
            ->whereNotNull('target_start_at')
            ->where('target_start_at', '<', $to)
            ->where(fn ($q) => $q->whereNull('target_completion_at')->orWhere('target_completion_at', '>', $from))
            ->orderBy('target_start_at')
            ->get(['wo_number', 'status', 'maintenance_type', 'target_start_at', 'target_completion_at']);

        $blockers = [];
        if ($vehicle->operational_status !== 'AVAILABLE') {
            $blockers[] = ['code' => 'VEHICLE_'.$vehicle->operational_status, 'status' => $vehicle->status];
        }
        foreach ($windows as $order) {
            $blockers[] = [
                'code' => 'SERVICE_SCHEDULED',
                'work_order' => $order->wo_number,
                'maintenance_type' => $order->maintenance_type,
                'starts_at' => CarbonImmutable::parse($order->target_start_at)->utc()->toIso8601String(),
                'ends_at' => $order->target_completion_at ? CarbonImmutable::parse($order->target_completion_at)->utc()->toIso8601String() : null,
            ];
        }

        return [
            'vehicle_id' => $vehicle->id,
            'registration_number' => $vehicle->registration_number,
            'operational_status' => $vehicle->operational_status,
            'status' => $vehicle->status,
            'window' => ['from' => $from->utc()->toIso8601String(), 'to' => $to->utc()->toIso8601String()],
            'available' => $blockers === [],
            'blockers' => $blockers,
            'as_of' => now()->utc()->toIso8601String(),
        ];
    }
}
