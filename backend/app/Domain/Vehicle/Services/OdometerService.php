<?php

namespace App\Domain\Vehicle\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Integration\Models\VehicleOdometerReading;
use App\Domain\Vehicle\Models\Vehicle;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The one place that moves `vehicles.current_odometer`, and the ledger (`vehicle_odometer_readings`) that explains every move.
 *
 * Rules:
 *  - The odometer only goes up on its own. A manual entry, an inspection, a work order, a tire operation, a vehicle release and a
 *    telematics device each offer a value; a value that is not higher is kept in the ledger and changes nothing.
 *  - Lowering it (or putting an older measurement above a newer one) is a deliberate correction by a person with a reason; it is
 *    stored as a `correction` and audited. Telematics never does this (see VehicleOdometerService, which quarantines instead).
 *  - Every move records where it came from (`odometer_source`), when it was measured and which ledger row did it. A vehicle whose
 *    odometer predates this ledger keeps NULL there: unknown stays unknown, nothing is guessed.
 *  - Kilometres are decimal strings with two places (brick/math, half-up); never floats.
 *  - The vehicle row is locked for the whole decision, so two writers cannot both raise from the same old value.
 */
class OdometerService
{
    public const SOURCE_MANUAL = 'manual';

    public const SOURCE_TELEMATICS = 'optinexus';

    public const SOURCE_INSPECTION = 'inspection';

    public const SOURCE_WORK_ORDER = 'work_order';

    public const SOURCE_TIRE_OPERATION = 'tire_operation';

    public const SOURCE_VEHICLE_RELEASE = 'vehicle_release';

    /** The column holds 12 digits with 2 decimals; stay far below it. */
    public const MAX_KM = '99999999.99';

    public function __construct(private readonly AuditService $audit) {}

    /** Exact decimal with two places; null when the input is not a plain non-negative number within range. */
    public static function normalize(mixed $value): ?string
    {
        if (is_int($value)) {
            $value = (string) $value;
        } elseif (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }
            $value = number_format($value, 6, '.', '');
        }
        if (! is_string($value) || ! preg_match('/^\d{1,12}(\.\d{1,9})?$/', trim($value))) {
            return null;
        }

        try {
            $decimal = BigDecimal::of(trim($value))->toScale(2, RoundingMode::HALF_UP);
        } catch (MathException) {
            return null;
        }

        return $decimal->isLessThanOrEqualTo(self::MAX_KM) ? (string) $decimal : null;
    }

    /** The odometer as the screens show it: value, unit and where it came from (nulls when that is not recorded). */
    public function current(Vehicle $vehicle): array
    {
        return [
            'value' => self::normalize((string) ($vehicle->current_odometer ?? '0')) ?? '0.00',
            'unit' => 'km',
            'source' => $vehicle->odometer_source,
            'measured_at' => $vehicle->odometer_measured_at?->toIso8601String(),
            'reading_id' => $vehicle->odometer_reading_id,
            // False for an odometer that was typed in before the ledger existed: nothing is known about its origin.
            'provenance_known' => $vehicle->odometer_source !== null,
        ];
    }

    /**
     * A person's odometer entry (the manual flow, no telematics needed).
     *
     * @param  string|null  $idempotencyKey  a UUID chosen by the client; the same key never records twice
     * @param  string|null  $correctionReason  required to lower the odometer or to put an older measurement above a newer one
     * @return array{reading: VehicleOdometerReading, duplicate: bool, outcome: string}
     *
     * @throws OdometerRejected
     */
    public function recordManual(Vehicle $vehicle, mixed $km, ?CarbonInterface $measuredAt, ?string $userId, ?string $note = null, ?string $idempotencyKey = null, ?string $correctionReason = null): array
    {
        $value = self::normalize($km) ?? throw OdometerRejected::invalid();
        $received = now();
        $measuredAt = ($measuredAt ?? $received)->copy()->utc();
        if ($measuredAt->gt($received->copy()->addSeconds((int) config('optinexus.telematics.future_tolerance_seconds')))) {
            throw OdometerRejected::futureTime();
        }
        $reason = $correctionReason !== null ? trim($correctionReason) : null;

        return DB::transaction(function () use ($vehicle, $value, $measuredAt, $received, $userId, $note, $idempotencyKey, $reason) {
            $locked = $this->lock($vehicle->tenant_id, $vehicle->id);

            if ($idempotencyKey !== null) {
                $existing = VehicleOdometerReading::query()->withoutGlobalScopes()
                    ->where(['tenant_id' => $locked->tenant_id, 'source' => self::SOURCE_MANUAL, 'source_reading_id' => $idempotencyKey])->first();
                if ($existing) {
                    if ($existing->vehicle_id !== $locked->id || ! BigDecimal::of((string) $existing->reported_km)->isEqualTo($value)) {
                        throw OdometerRejected::idempotencyConflict();
                    }

                    return ['reading' => $existing, 'duplicate' => true, 'outcome' => $existing->disposition];
                }
            }

            $current = BigDecimal::of((string) ($locked->current_odometer ?? '0'))->toScale(2);
            $currentAt = $locked->odometer_measured_at;
            $new = BigDecimal::of($value);

            $lower = $new->isLessThan($current);
            $olderThanCurrent = $new->isGreaterThan($current) && $currentAt !== null && $measuredAt->lt($currentAt);
            if (($lower || $olderThanCurrent) && ($reason === null || mb_strlen($reason) < 10)) {
                throw $lower ? OdometerRejected::belowCurrent((string) $current) : OdometerRejected::timeConflict((string) $current, $currentAt?->toIso8601String());
            }

            $disposition = match (true) {
                $lower || $olderThanCurrent => VehicleOdometerReading::DISPOSITION_CORRECTION,
                $new->isGreaterThan($current) || $locked->odometer_source === null => VehicleOdometerReading::DISPOSITION_APPLIED,
                default => VehicleOdometerReading::DISPOSITION_STORED,
            };

            $reading = VehicleOdometerReading::query()->create([
                'tenant_id' => $locked->tenant_id,
                'vehicle_id' => $locked->id,
                'source' => self::SOURCE_MANUAL,
                'source_reading_id' => $idempotencyKey ?? (string) Str::uuid(),
                'device_ref' => null,
                'odometer_kind' => VehicleOdometerReading::KIND_MANUAL,
                'reported_km' => $value,
                'effective_km' => $value,
                'previous_odometer' => (string) $current,
                'applied' => in_array($disposition, [VehicleOdometerReading::DISPOSITION_APPLIED, VehicleOdometerReading::DISPOSITION_CORRECTION], true),
                'recorded_at' => $measuredAt,
                'received_at' => $received,
                'unit' => 'km',
                'disposition' => $disposition,
                'entered_by' => $userId,
                'note' => $reason ?? $note,
            ]);

            if ($reading->applied) {
                $this->setCurrent($locked, $value, self::SOURCE_MANUAL, $measuredAt, $reading->id);
            }
            if ($disposition === VehicleOdometerReading::DISPOSITION_CORRECTION) {
                $this->audit->log('Vehicle', $locked->id, 'odometer_corrected', ['current_odometer' => (string) $current, 'odometer_measured_at' => $currentAt?->toIso8601String()], [
                    'current_odometer' => $value, 'odometer_measured_at' => $measuredAt->toIso8601String(), 'reason' => $reason, 'reading_id' => $reading->id,
                ], $locked->tenant_id);
            }

            return ['reading' => $reading, 'duplicate' => false, 'outcome' => $disposition];
        });
    }

    /**
     * Another document of the system reports the vehicle's odometer (inspection, work order, tire operation, vehicle release):
     * raises it when higher, otherwise changes nothing and writes nothing. Runs inside the caller's transaction.
     */
    public function raiseFromOperation(Vehicle $vehicle, mixed $km, string $source, string $referenceType, string $referenceId, ?CarbonInterface $measuredAt = null, ?string $userId = null): ?VehicleOdometerReading
    {
        $value = self::normalize($km);
        if ($value === null) {
            return null;
        }
        $at = ($measuredAt ?? now())->copy()->utc();

        return DB::transaction(function () use ($vehicle, $value, $source, $referenceType, $referenceId, $at, $userId) {
            $locked = $this->lock($vehicle->tenant_id, $vehicle->id);
            $current = BigDecimal::of((string) ($locked->current_odometer ?? '0'))->toScale(2);
            if (! BigDecimal::of($value)->isGreaterThan($current)) {
                return null;
            }

            $reading = VehicleOdometerReading::query()->create([
                'tenant_id' => $locked->tenant_id,
                'vehicle_id' => $locked->id,
                'source' => $source,
                'source_reading_id' => (string) Str::uuid(),
                'odometer_kind' => VehicleOdometerReading::KIND_MANUAL,
                'reported_km' => $value,
                'effective_km' => $value,
                'previous_odometer' => (string) $current,
                'applied' => true,
                'recorded_at' => $at,
                'received_at' => now(),
                'unit' => 'km',
                'disposition' => VehicleOdometerReading::DISPOSITION_APPLIED,
                'entered_by' => $userId,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]);
            $this->setCurrent($locked, $value, $source, $at, $reading->id);
            $this->mirror($locked, $vehicle);

            return $reading;
        });
    }

    /** The odometer given when a vehicle is created (form or import). A zero is the column default, not a reading, and records nothing. */
    public function recordInitial(Vehicle $vehicle, ?string $userId): ?VehicleOdometerReading
    {
        $value = self::normalize((string) ($vehicle->current_odometer ?? '0'));
        if ($value === null || BigDecimal::of($value)->isZero()) {
            return null;
        }

        return DB::transaction(function () use ($vehicle, $value, $userId) {
            $at = now();
            $reading = VehicleOdometerReading::query()->create([
                'tenant_id' => $vehicle->tenant_id,
                'vehicle_id' => $vehicle->id,
                'source' => self::SOURCE_MANUAL,
                'source_reading_id' => (string) Str::uuid(),
                'odometer_kind' => VehicleOdometerReading::KIND_MANUAL,
                'reported_km' => $value,
                'effective_km' => $value,
                'previous_odometer' => '0.00',
                'applied' => true,
                'recorded_at' => $at,
                'received_at' => $at,
                'unit' => 'km',
                'disposition' => VehicleOdometerReading::DISPOSITION_APPLIED,
                'entered_by' => $userId,
                'note' => 'initial',
            ]);
            $this->setCurrent($vehicle, $value, self::SOURCE_MANUAL, $at, $reading->id);

            return $reading;
        });
    }

    /**
     * Writes the new odometer and its provenance through the model (so the audit trail records it). The caller holds the lock.
     */
    public function setCurrent(Vehicle $locked, string $km, string $source, CarbonInterface $measuredAt, string $readingId): void
    {
        $locked->forceFill([
            'current_odometer' => $km,
            'odometer_source' => $source,
            'odometer_measured_at' => $measuredAt->copy()->utc(),
            'odometer_reading_id' => $readingId,
        ])->save();
    }

    /** Keeps the caller's own model instance current without touching anything else it may have changed. */
    private function mirror(Vehicle $from, Vehicle $to): void
    {
        $keys = ['current_odometer', 'odometer_source', 'odometer_measured_at', 'odometer_reading_id'];
        foreach ($keys as $key) {
            $to->setAttribute($key, $from->getAttribute($key));
        }
        $to->syncOriginalAttributes($keys);
    }

    public function lock(string $tenantId, string $vehicleId): Vehicle
    {
        return Vehicle::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->where('id', $vehicleId)->lockForUpdate()->firstOrFail();
    }
}
