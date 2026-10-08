<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Integration\Models\VehicleOdometerReading;
use App\Domain\Integration\Models\VehicleTelematicsLink;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Applies telematics readings to a vehicle's odometer.
 *
 * Rules (owner decisions):
 *  - Manual odometer entry in OptiFleet is untouched; telematics only ever
 *    *raises* `current_odometer` (never lowers it), so tenants without
 *    OptiRadar behave exactly as before.
 *  - DEVICE_ODOMETER readings are the vehicle's real odometer: applied as is.
 *  - GPS_DISTANCE readings are distance since tracker installation. They are
 *    stored but HELD until an admin calibrates the vehicle (offset), then
 *    effective = reported + offset.
 *  - Money-grade decimal math (BCMath) end to end; no floats.
 *  - Every reading is stored once (unique per source reading id), and the
 *    vehicle row is locked while it is applied, so concurrent syncs cannot
 *    lose or double-apply an update.
 */
class VehicleOdometerService
{
    public const SOURCE = 'optinexus';

    public const RESULT_APPLIED = 'applied';

    public const RESULT_STORED = 'stored';

    public const RESULT_HELD = 'held';

    public const RESULT_DUPLICATE = 'duplicate';

    public const RESULT_UNKNOWN_VEHICLE = 'unknown_vehicle';

    /**
     * @param  array<string, mixed>  $item  one item of the gateway feed
     */
    public function record(string $tenantId, array $item): string
    {
        return DB::transaction(function () use ($tenantId, $item) {
            $vehicle = Vehicle::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('id', (string) $item['vehicle_id'])->lockForUpdate()->first();
            if (! $vehicle) {
                return self::RESULT_UNKNOWN_VEHICLE;
            }

            $exists = VehicleOdometerReading::query()->withoutGlobalScopes()
                ->where(['tenant_id' => $tenantId, 'source' => self::SOURCE, 'source_reading_id' => $item['reading_id']])->exists();
            if ($exists) {
                return self::RESULT_DUPLICATE;
            }

            $link = VehicleTelematicsLink::query()->withoutGlobalScopes()->firstOrCreate([
                'tenant_id' => $tenantId, 'vehicle_id' => $vehicle->id, 'source' => self::SOURCE, 'device_ref' => (string) $item['device_ref'],
            ]);

            $kind = $item['odometer_kind'] ?? VehicleOdometerReading::KIND_DEVICE;
            $reported = $this->decimal($item['odometer_km']);
            $effective = match (true) {
                $kind === VehicleOdometerReading::KIND_DEVICE => $reported,
                $link->isCalibrated() => bcadd($reported, (string) $link->odometer_offset_km, 2),
                default => null,
            };

            $previous = $this->decimal($vehicle->current_odometer);
            $applied = $effective !== null && bccomp($effective, $previous, 2) === 1;

            VehicleOdometerReading::query()->withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'vehicle_id' => $vehicle->id,
                'source' => self::SOURCE,
                'source_reading_id' => $item['reading_id'],
                'device_ref' => (string) $item['device_ref'],
                'odometer_kind' => $kind,
                'reported_km' => $reported,
                'effective_km' => $effective,
                'previous_odometer' => $previous,
                'applied' => $applied,
                'recorded_at' => Carbon::parse($item['recorded_at'])->utc(),
            ]);

            if ($applied) {
                $vehicle->forceFill(['current_odometer' => $effective])->save();

                return self::RESULT_APPLIED;
            }

            return $effective === null ? self::RESULT_HELD : self::RESULT_STORED;
        });
    }

    /**
     * Sets the calibration of a GPS-distance link and applies what was held.
     * Either the offset itself or today's real (dashboard) odometer is given;
     * the latter derives the offset from the newest reading of the device.
     */
    public function calibrate(VehicleTelematicsLink $link, ?string $offsetKm, ?string $actualOdometerKm, ?string $userId): VehicleTelematicsLink
    {
        return DB::transaction(function () use ($link, $offsetKm, $actualOdometerKm, $userId) {
            $vehicle = Vehicle::query()->withoutGlobalScopes()
                ->where('tenant_id', $link->tenant_id)->where('id', $link->vehicle_id)->lockForUpdate()->firstOrFail();

            $readings = VehicleOdometerReading::query()->withoutGlobalScopes()
                ->where(['tenant_id' => $link->tenant_id, 'vehicle_id' => $link->vehicle_id, 'source' => $link->source, 'device_ref' => $link->device_ref, 'odometer_kind' => VehicleOdometerReading::KIND_GPS]);

            if ($offsetKm === null) {
                $latest = (clone $readings)->orderByDesc('recorded_at')->first();
                if (! $latest) {
                    throw new \InvalidArgumentException("There is no GPS reading to calibrate from yet.");
                }
                $offsetKm = bcsub($this->decimal($actualOdometerKm), (string) $latest->reported_km, 2);
            }

            $link->forceFill([
                'odometer_offset_km' => $this->decimal($offsetKm),
                'calibrated_by' => $userId,
                'calibrated_at' => now(),
            ])->save();

            // Held and previously stored readings now have a meaningful value.
            foreach ((clone $readings)->orderBy('recorded_at')->get() as $reading) {
                $reading->effective_km = bcadd((string) $reading->reported_km, (string) $link->odometer_offset_km, 2);
                $reading->save();
            }

            $newest = (clone $readings)->orderByDesc('recorded_at')->first();
            if ($newest && bccomp((string) $newest->effective_km, $this->decimal($vehicle->current_odometer), 2) === 1) {
                $newest->forceFill(['previous_odometer' => $vehicle->current_odometer, 'applied' => true])->save();
                $vehicle->forceFill(['current_odometer' => $newest->effective_km])->save();
            }

            return $link->refresh();
        });
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) $value, '0', 2);
    }
}
