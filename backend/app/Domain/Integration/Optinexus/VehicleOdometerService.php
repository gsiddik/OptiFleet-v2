<?php

namespace App\Domain\Integration\Optinexus;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Integration\Models\VehicleOdometerReading;
use App\Domain\Integration\Models\VehicleTelematicsLink;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\Vehicle\Services\OdometerService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Applies telematics readings (OptiNexus gateway feed) to a vehicle's odometer. The ledger and the one place that moves the
 * odometer are in OdometerService; this class decides what a device reading is allowed to do.
 *
 * Rules (owner decisions, unchanged):
 *  - Manual odometer entry in OptiFleet is untouched; telematics only ever *raises* `current_odometer` (never lowers it), so tenants
 *    without OptiRadar behave exactly as before.
 *  - DEVICE_ODOMETER readings are the vehicle's real odometer: applied as is (plus a rebase offset when a person aligned the device).
 *  - GPS_DISTANCE readings are distance since tracker installation. They are stored but HELD until an admin calibrates the vehicle
 *    (offset), then effective = reported + offset.
 *  - Decimal math end to end (brick/math); no floats. Every reading is stored once (unique per source reading id), and the vehicle
 *    row is locked while it is applied, so concurrent syncs cannot lose or double-apply an update.
 *
 * Anomalies (audit A10). A reading is judged against the last one accepted from the same device and kind, and is QUARANTINED (kept,
 * never applied, never lowers anything) when it
 *   - was measured in the future (device clock fault)                     FUTURE_TIME   (informational, no review needed)
 *   - is lower than that value by more than the tolerance (reset)         COUNTER_DECREASE
 *   - is higher than a vehicle could have driven since then               IMPLAUSIBLE_JUMP
 *   - is the first odometer of a new device on a vehicle that had one     DEVICE_CHANGED
 * The device link then goes under review: its later readings are kept as quarantined until a person decides (resolveReview):
 * dismiss (the reading was wrong), accept (it is the truth; lowering needs a reason and is a correction) or rebase (a replaced or
 * reset device: align it to the odometer recorded here). A reading strictly older than what is already held for the device is stored as
 * `late` and never applied. Missing or malformed values are rejected, counted and never zero-filled.
 */
class VehicleOdometerService
{
    public const SOURCE = OdometerService::SOURCE_TELEMATICS;

    public const RESULT_APPLIED = 'applied';

    public const RESULT_STORED = 'stored';

    public const RESULT_HELD = 'held';

    public const RESULT_LATE = 'late';

    public const RESULT_QUARANTINED = 'quarantined';

    public const RESULT_DUPLICATE = 'duplicate';

    public const RESULT_UNKNOWN_VEHICLE = 'unknown_vehicle';

    public const RESULT_REJECTED = 'rejected';

    public const ANOMALY_DECREASE = 'COUNTER_DECREASE';

    public const ANOMALY_JUMP = 'IMPLAUSIBLE_JUMP';

    public const ANOMALY_DEVICE_CHANGED = 'DEVICE_CHANGED';

    public const ANOMALY_FUTURE_TIME = 'FUTURE_TIME';

    public const DECISION_DISMISS = 'dismiss';

    public const DECISION_ACCEPT = 'accept';

    public const DECISION_REBASE = 'rebase';

    public function __construct(
        private readonly OdometerService $odometer,
        private readonly AuditService $audit,
    ) {}

    /**
     * Why an item of the gateway feed cannot be used at all (null when it can). Such an item is rejected and counted by the caller;
     * it never stops the feed and never becomes a zero.
     */
    public function invalidReason(array $item): ?string
    {
        if (! isset($item['reading_id']) || ! $this->isUuid($item['reading_id'])) {
            return 'INVALID_READING_ID';
        }
        if (! isset($item['vehicle_id']) || ! $this->isUuid($item['vehicle_id'])) {
            return 'INVALID_VEHICLE_ID';
        }
        if (! isset($item['device_ref']) || ! is_scalar($item['device_ref']) || trim((string) $item['device_ref']) === '' || mb_strlen((string) $item['device_ref']) > 255) {
            return 'INVALID_DEVICE_REF';
        }
        if (! array_key_exists('odometer_km', $item) || OdometerService::normalize($item['odometer_km']) === null) {
            return 'INVALID_ODOMETER';
        }
        if (! isset($item['recorded_at']) || $this->time($item['recorded_at']) === null) {
            return 'INVALID_TIME';
        }
        if (isset($item['odometer_kind']) && ! in_array($item['odometer_kind'], [VehicleOdometerReading::KIND_DEVICE, VehicleOdometerReading::KIND_GPS], true)) {
            return 'INVALID_KIND';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $item  one item of the gateway feed
     */
    public function record(string $tenantId, array $item, ?CarbonInterface $receivedAt = null): string
    {
        if ($this->invalidReason($item) !== null) {
            return self::RESULT_REJECTED;
        }

        $received = CarbonImmutable::instance($receivedAt ?? now())->utc();
        $measuredAt = $this->time($item['recorded_at']);
        $kind = $item['odometer_kind'] ?? VehicleOdometerReading::KIND_DEVICE;
        $reported = BigDecimal::of(OdometerService::normalize($item['odometer_km']));

        return DB::transaction(function () use ($tenantId, $item, $received, $measuredAt, $kind, $reported) {
            $vehicle = Vehicle::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)->where('id', $item['vehicle_id'])->lockForUpdate()->first();
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
            $link->refresh();

            $current = BigDecimal::of((string) $vehicle->current_odometer)->toScale(2);
            [$disposition, $anomaly, $effective] = $this->judge($vehicle, $link, $kind, $reported, $measuredAt, $received);

            $opensReview = $disposition === VehicleOdometerReading::DISPOSITION_QUARANTINED && $anomaly !== self::ANOMALY_FUTURE_TIME && ! $link->underReview();
            $applied = $disposition === VehicleOdometerReading::DISPOSITION_APPLIED;

            $reading = VehicleOdometerReading::query()->withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'vehicle_id' => $vehicle->id,
                'source' => self::SOURCE,
                'source_reading_id' => $item['reading_id'],
                'device_ref' => (string) $item['device_ref'],
                'odometer_kind' => $kind,
                'reported_km' => (string) $reported,
                'effective_km' => $effective !== null ? (string) $effective : null,
                'previous_odometer' => (string) $current,
                'applied' => $applied,
                'recorded_at' => $measuredAt,
                'origin' => isset($item['source']) && is_string($item['source']) ? mb_substr($item['source'], 0, 40) : null,
                'received_at' => $received,
                'unit' => 'km',
                'disposition' => $disposition,
                'anomaly_code' => $anomaly,
                'review_status' => $opensReview ? VehicleOdometerReading::REVIEW_PENDING : null,
            ]);

            $this->trackLink($link, $kind, $reported, $measuredAt, $received, $disposition, $anomaly, $opensReview ? $reading->id : null);

            if ($applied) {
                $this->odometer->setCurrent($vehicle, (string) $effective, self::SOURCE, $measuredAt, $reading->id);
            }

            return $disposition;
        });
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?BigDecimal} disposition, anomaly code, effective km (null = not usable yet)
     */
    private function judge(Vehicle $vehicle, VehicleTelematicsLink $link, string $kind, BigDecimal $reported, CarbonImmutable $measuredAt, CarbonImmutable $received): array
    {
        $quarantine = fn (string $code) => [VehicleOdometerReading::DISPOSITION_QUARANTINED, $code, null];

        if ($measuredAt->gt($received->addSeconds((int) config('optinexus.telematics.future_tolerance_seconds')))) {
            return $quarantine(self::ANOMALY_FUTURE_TIME);
        }

        if ($link->underReview()) {
            return $quarantine((string) $link->review_reason);
        }

        if ($link->last_measured_at !== null && $measuredAt->lt($link->last_measured_at)) {
            return [VehicleOdometerReading::DISPOSITION_LATE, null, $this->effective($link, $kind, $reported)];
        }

        $baseline = $link->baseline($kind);
        if ($baseline === null) {
            if ($kind === VehicleOdometerReading::KIND_DEVICE && $this->vehicleHadAnotherDevice($link)) {
                return $quarantine(self::ANOMALY_DEVICE_CHANGED);
            }
        } else {
            $base = BigDecimal::of($baseline['km']);
            if ($reported->isLessThan($base->minus((string) config('optinexus.telematics.decrease_tolerance_km')))) {
                return $quarantine(self::ANOMALY_DECREASE);
            }
            if ($reported->minus($base)->isGreaterThan($this->plausibleDistance(max(0, $measuredAt->getTimestamp() - CarbonImmutable::parse($baseline['at'])->getTimestamp())))) {
                return $quarantine(self::ANOMALY_JUMP);
            }
        }

        $effective = $this->effective($link, $kind, $reported);
        if ($effective === null) {
            return [VehicleOdometerReading::DISPOSITION_HELD, null, null];
        }
        $current = BigDecimal::of((string) $vehicle->current_odometer);

        return [$effective->isGreaterThan($current) ? VehicleOdometerReading::DISPOSITION_APPLIED : VehicleOdometerReading::DISPOSITION_STORED, null, $effective];
    }

    /** The most a vehicle can cover in the given time: a fixed slack plus the time at the largest average speed. */
    private function plausibleDistance(int $seconds): BigDecimal
    {
        return BigDecimal::of($seconds)->multipliedBy((string) config('optinexus.telematics.jump_max_average_kmh'))
            ->dividedBy('3600', 4, RoundingMode::HALF_UP)->plus((string) config('optinexus.telematics.jump_slack_km'));
    }

    private function vehicleHadAnotherDevice(VehicleTelematicsLink $link): bool
    {
        return VehicleTelematicsLink::query()->withoutGlobalScopes()
            ->where(['tenant_id' => $link->tenant_id, 'vehicle_id' => $link->vehicle_id, 'source' => $link->source])
            ->where('id', '!=', $link->id)
            ->get()
            ->contains(fn (VehicleTelematicsLink $other) => $other->baseline(VehicleOdometerReading::KIND_DEVICE) !== null);
    }

    /** The odometer a reading means for the vehicle; null while a GPS-distance device is not calibrated. */
    private function effective(VehicleTelematicsLink $link, string $kind, BigDecimal $reported): ?BigDecimal
    {
        if ($kind === VehicleOdometerReading::KIND_DEVICE) {
            return $link->rebase_offset_km !== null ? $reported->plus((string) $link->rebase_offset_km)->toScale(2) : $reported;
        }

        return $link->isCalibrated() ? $reported->plus((string) $link->odometer_offset_km)->toScale(2) : null;
    }

    private function trackLink(VehicleTelematicsLink $link, string $kind, BigDecimal $reported, CarbonImmutable $measuredAt, CarbonImmutable $received, string $disposition, ?string $anomaly, ?string $reviewReadingId): void
    {
        $attributes = [];

        // A future time is a clock fault: it must not become "the newest reading", or every real one after it would look late.
        if ($anomaly !== self::ANOMALY_FUTURE_TIME) {
            if ($disposition !== VehicleOdometerReading::DISPOSITION_LATE) {
                $attributes['last_measured_at'] = $measuredAt;
            }
            if (in_array($disposition, [VehicleOdometerReading::DISPOSITION_APPLIED, VehicleOdometerReading::DISPOSITION_STORED, VehicleOdometerReading::DISPOSITION_HELD], true)) {
                $baselines = $link->baselines ?? [];
                $baselines[$kind] = ['km' => (string) $reported, 'at' => $measuredAt->toIso8601String()];
                $attributes['baselines'] = $baselines;
            }
        }
        $attributes['last_received_at'] = $received;

        if ($reviewReadingId !== null) {
            $attributes += [
                'status' => VehicleTelematicsLink::STATUS_REVIEW,
                'review_reason' => $anomaly,
                'review_opened_at' => $received,
                'review_reading_id' => $reviewReadingId,
            ];
        }

        $link->forceFill($attributes)->save();
    }

    /**
     * Sets the calibration of a GPS-distance link and applies what was held.
     * Either the offset itself or today's real (dashboard) odometer is given;
     * the latter derives the offset from the newest reading of the device.
     *
     * @throws TelematicsReviewPending when the device is under review (resolve that first: a calibration must not carry a quarantined reading)
     */
    public function calibrate(VehicleTelematicsLink $link, ?string $offsetKm, ?string $actualOdometerKm, ?string $userId): VehicleTelematicsLink
    {
        return DB::transaction(function () use ($link, $offsetKm, $actualOdometerKm, $userId) {
            $vehicle = $this->odometer->lock($link->tenant_id, $link->vehicle_id);
            $link = VehicleTelematicsLink::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($link->id);
            if ($link->underReview()) {
                throw new TelematicsReviewPending;
            }

            // Only readings that were not judged an anomaly or late take part: a quarantined or late value never becomes the odometer here.
            $readings = VehicleOdometerReading::query()->withoutGlobalScopes()
                ->where(['tenant_id' => $link->tenant_id, 'vehicle_id' => $link->vehicle_id, 'source' => $link->source, 'device_ref' => $link->device_ref, 'odometer_kind' => VehicleOdometerReading::KIND_GPS])
                ->whereIn('disposition', [VehicleOdometerReading::DISPOSITION_HELD, VehicleOdometerReading::DISPOSITION_STORED, VehicleOdometerReading::DISPOSITION_APPLIED]);

            if ($offsetKm === null) {
                $latest = (clone $readings)->orderByDesc('recorded_at')->first();
                if (! $latest) {
                    throw new \InvalidArgumentException('There is no GPS reading to calibrate from yet.');
                }
                $actual = OdometerService::normalize($actualOdometerKm) ?? throw new \InvalidArgumentException('The actual odometer is not a valid number.');
                $offsetKm = (string) BigDecimal::of($actual)->minus((string) $latest->reported_km)->toScale(2);
            }
            $offset = $this->signed($offsetKm);

            $link->forceFill([
                'odometer_offset_km' => $offset,
                'calibrated_by' => $userId,
                'calibrated_at' => now(),
            ])->save();

            // Held and previously stored readings now have a meaningful value.
            foreach ((clone $readings)->orderBy('recorded_at')->get() as $reading) {
                $reading->effective_km = (string) BigDecimal::of((string) $reading->reported_km)->plus($offset)->toScale(2);
                if ($reading->disposition === VehicleOdometerReading::DISPOSITION_HELD) {
                    $reading->disposition = VehicleOdometerReading::DISPOSITION_STORED;
                }
                $reading->save();
            }

            $newest = (clone $readings)->orderByDesc('recorded_at')->first();
            if ($newest && BigDecimal::of((string) $newest->effective_km)->isGreaterThan(BigDecimal::of((string) $vehicle->current_odometer))) {
                $newest->forceFill(['previous_odometer' => $vehicle->current_odometer, 'applied' => true, 'disposition' => VehicleOdometerReading::DISPOSITION_APPLIED])->save();
                $this->odometer->setCurrent($vehicle, (string) $newest->effective_km, self::SOURCE, CarbonImmutable::instance($newest->recorded_at), $newest->id);
            }

            return $link->refresh();
        });
    }

    /**
     * A person decides what a device under review means. Always with a note; always audited; the quarantined reading is the evidence.
     *
     * @throws TelematicsReviewRejected
     */
    public function resolveReview(VehicleTelematicsLink $link, string $decision, string $note, ?string $actualOdometerKm, ?string $userId): VehicleTelematicsLink
    {
        $note = trim($note);
        if (! in_array($decision, [self::DECISION_DISMISS, self::DECISION_ACCEPT, self::DECISION_REBASE], true)) {
            throw TelematicsReviewRejected::invalidDecision();
        }
        if (mb_strlen($note) < 5) {
            throw TelematicsReviewRejected::noteRequired();
        }

        return DB::transaction(function () use ($link, $decision, $note, $actualOdometerKm, $userId) {
            $vehicle = $this->odometer->lock($link->tenant_id, $link->vehicle_id);
            $link = VehicleTelematicsLink::query()->withoutGlobalScopes()->lockForUpdate()->findOrFail($link->id);
            $reading = $link->underReview() && $link->review_reading_id
                ? VehicleOdometerReading::query()->withoutGlobalScopes()->lockForUpdate()->find($link->review_reading_id) : null;
            if (! $reading || $reading->review_status !== VehicleOdometerReading::REVIEW_PENDING) {
                throw TelematicsReviewRejected::notUnderReview();
            }

            $kind = $reading->odometer_kind;
            $reported = BigDecimal::of((string) $reading->reported_km);
            $measuredAt = CarbonImmutable::instance($reading->recorded_at);
            $current = BigDecimal::of((string) $vehicle->current_odometer)->toScale(2);
            $before = ['current_odometer' => (string) $current];
            $status = match ($decision) {
                self::DECISION_DISMISS => VehicleOdometerReading::REVIEW_DISMISSED,
                self::DECISION_ACCEPT => VehicleOdometerReading::REVIEW_ACCEPTED,
                self::DECISION_REBASE => VehicleOdometerReading::REVIEW_REBASED,
            };
            $updates = ['review_status' => $status, 'reviewed_by' => $userId, 'reviewed_at' => now(), 'review_note' => $note];

            if ($decision === self::DECISION_ACCEPT) {
                $effective = $this->effective($link, $kind, $reported) ?? throw TelematicsReviewRejected::calibrationRequired();
                $this->adopt($vehicle, $reading, $effective, $current, $measuredAt, $note, $updates);
                $this->moveBaseline($link, $kind, $reported, $measuredAt);
            } elseif ($decision === self::DECISION_REBASE) {
                $actual = $actualOdometerKm !== null && $actualOdometerKm !== '' ? (OdometerService::normalize($actualOdometerKm) ?? throw TelematicsReviewRejected::invalidOdometer()) : (string) $current;
                $target = BigDecimal::of($actual);
                if ($target->isLessThan($current)) {
                    // Aligning a device must not lower the odometer: a person who knows it is too high corrects it first, with a reason.
                    throw TelematicsReviewRejected::belowCurrent((string) $current);
                }
                $offset = $target->minus($reported)->toScale(2);
                if ($kind === VehicleOdometerReading::KIND_DEVICE) {
                    $link->forceFill(['rebase_offset_km' => $offset])->save();
                } else {
                    $link->forceFill(['odometer_offset_km' => $offset, 'calibrated_by' => $userId, 'calibrated_at' => now()])->save();
                }
                $this->adopt($vehicle, $reading, $target, $current, $measuredAt, $note, $updates);
                $this->moveBaseline($link, $kind, $reported, $measuredAt);
            }

            $reading->forceFill($updates)->save();
            $link->forceFill([
                'status' => VehicleTelematicsLink::STATUS_ACTIVE,
                'review_reason' => null,
                'review_opened_at' => null,
                'review_reading_id' => null,
            ])->save();

            $this->audit->log('VehicleTelematicsLink', $link->id, 'telematics.review_'.$decision, $before + ['anomaly' => $reading->anomaly_code], [
                'decision' => $decision, 'note' => $note, 'reading_id' => $reading->id, 'vehicle_id' => $vehicle->id,
                'current_odometer' => (string) BigDecimal::of((string) $vehicle->fresh()->current_odometer)->toScale(2),
            ], $link->tenant_id);

            return $link->refresh();
        });
    }

    /**
     * The quarantined reading becomes the vehicle's odometer by a person's decision. Raising is like any reading; lowering is a
     * correction and needs a real reason (not just a note).
     *
     * @param  array<string, mixed>  $updates  reading columns being set by the caller
     */
    private function adopt(Vehicle $vehicle, VehicleOdometerReading $reading, BigDecimal $value, BigDecimal $current, CarbonImmutable $measuredAt, string $note, array &$updates): void
    {
        $lower = $value->isLessThan($current);
        if ($lower && mb_strlen($note) < 10) {
            throw TelematicsReviewRejected::reasonRequired((string) $current);
        }

        $updates['effective_km'] = (string) $value;
        $updates['previous_odometer'] = (string) $current;
        if ($value->isGreaterThan($current) || $lower) {
            $updates['applied'] = true;
            $updates['disposition'] = $lower ? VehicleOdometerReading::DISPOSITION_CORRECTION : VehicleOdometerReading::DISPOSITION_APPLIED;
            $this->odometer->setCurrent($vehicle, (string) $value, self::SOURCE, $measuredAt, $reading->id);
            if ($lower) {
                $this->audit->log('Vehicle', $vehicle->id, 'odometer_corrected', ['current_odometer' => (string) $current], [
                    'current_odometer' => (string) $value, 'reason' => $note, 'reading_id' => $reading->id,
                ], $vehicle->tenant_id);
            }
        } else {
            $updates['disposition'] = VehicleOdometerReading::DISPOSITION_STORED;
        }
    }

    private function moveBaseline(VehicleTelematicsLink $link, string $kind, BigDecimal $reported, CarbonImmutable $measuredAt): void
    {
        $baselines = $link->baselines ?? [];
        $baselines[$kind] = ['km' => (string) $reported, 'at' => $measuredAt->toIso8601String()];
        $link->forceFill(['baselines' => $baselines])->save();
    }

    private function signed(string $value): string
    {
        try {
            return (string) BigDecimal::of(trim($value))->toScale(2, RoundingMode::HALF_UP);
        } catch (Throwable) {
            throw new \InvalidArgumentException('The offset is not a valid number.');
        }
    }

    private function isUuid(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $value) === 1;
    }

    private function time(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '' || mb_strlen($value) > 64) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
