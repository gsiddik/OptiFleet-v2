<?php

namespace App\Domain\Vehicle\Services;

use App\Domain\Identity\Models\Tenant;
use App\Domain\Integration\Services\IntegrationOutboxService;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one place that moves a vehicle between AVAILABLE / IN_USE / ON_HOLD. When `operational_status` really changes and the
 * tenant is linked to OptiNexus, an `optifleet.vehicle.availability_changed` fact is written to the integration outbox in the
 * same transaction, so a transport system (OptiDeli) hears about it through the OptiNexus event broker. Every change is its own
 * occurrence (a fresh aggregate id), because a vehicle goes back and forth and the outbox is unique per aggregate.
 */
class VehicleAvailabilityService
{
    public const EVENT_TYPE = 'vehicle.availability_changed';

    public function __construct(private readonly IntegrationOutboxService $outbox) {}

    /**
     * @param  array<string, mixed>  $attributes  vehicle columns to set (typically `status` and `operational_status`)
     */
    public function change(Vehicle|string $vehicle, array $attributes): Vehicle
    {
        return DB::transaction(function () use ($vehicle, $attributes) {
            $locked = Vehicle::query()->whereKey($vehicle instanceof Vehicle ? $vehicle->id : $vehicle)->lockForUpdate()->firstOrFail();
            $previous = $locked->operational_status;

            $locked->update($attributes);

            if ($locked->operational_status !== $previous) {
                $this->announce($locked, $previous);
            }

            if ($vehicle instanceof Vehicle) {
                $vehicle->setRawAttributes($locked->getAttributes(), true);
            }

            return $locked;
        });
    }

    private function announce(Vehicle $vehicle, ?string $previous): void
    {
        // An unlinked tenant has no one to tell; its rows would only pile up and be replayed stale on linking.
        if (! Tenant::query()->whereKey($vehicle->tenant_id)->whereNotNull('optinexus_tenant_id')->exists()) {
            return;
        }

        $this->outbox->record(
            $vehicle->tenant_id,
            self::EVENT_TYPE,
            'VehicleAvailabilityChange',
            (string) Str::uuid(),
            ['vehicle_id' => $vehicle->id],
            [
                'vehicle_id' => $vehicle->id,
                'registration_number' => $vehicle->registration_number,
                'operational_status' => $vehicle->operational_status,
                'previous_operational_status' => $previous,
                'status' => $vehicle->status,
                'changed_at' => now()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            ],
        );
    }
}
