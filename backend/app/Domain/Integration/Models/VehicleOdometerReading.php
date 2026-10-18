<?php

namespace App\Domain\Integration\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The odometer ledger of a vehicle: one row per value offered to it, whatever its source (telematics, manual entry, inspection,
 * work order, tire operation, vehicle release) and whether or not it moved `vehicles.current_odometer`.
 *
 *  - `recorded_at` is the time the value was MEASURED (device report time, or the time the person says they read it); `received_at`
 *    is when OptiFleet stored it. They are never swapped and `created_at` is never used as either.
 *  - `unit` is always km; values are decimal strings (2 places), never floats.
 *  - `disposition` says what was done with it: applied (raised the odometer), stored (kept, not higher), held (GPS distance waiting for
 *    calibration), late (older than what is already held for the device), quarantined (an anomaly: needs a person, never applied),
 *    correction (a person lowered or overrode the odometer, with a reason).
 */
class VehicleOdometerReading extends Model
{
    use BelongsToTenant, HasUuids;

    public const KIND_DEVICE = 'DEVICE_ODOMETER';

    public const KIND_GPS = 'GPS_DISTANCE';

    public const KIND_MANUAL = 'MANUAL_ENTRY';

    public const DISPOSITION_APPLIED = 'applied';

    public const DISPOSITION_STORED = 'stored';

    public const DISPOSITION_HELD = 'held';

    public const DISPOSITION_LATE = 'late';

    public const DISPOSITION_QUARANTINED = 'quarantined';

    public const DISPOSITION_CORRECTION = 'correction';

    public const REVIEW_PENDING = 'pending';

    public const REVIEW_REBASED = 'rebased';

    public const REVIEW_ACCEPTED = 'accepted';

    public const REVIEW_DISMISSED = 'dismissed';

    protected $fillable = [
        'tenant_id', 'vehicle_id', 'source', 'source_reading_id', 'device_ref', 'odometer_kind',
        'reported_km', 'effective_km', 'previous_odometer', 'applied', 'recorded_at',
        'origin', 'received_at', 'unit', 'disposition', 'anomaly_code', 'review_status', 'reviewed_by', 'reviewed_at', 'review_note',
        'entered_by', 'note', 'reference_type', 'reference_id',
    ];

    protected function casts(): array
    {
        return ['applied' => 'boolean', 'recorded_at' => 'datetime', 'received_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function enteredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'entered_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /** Fleet's own assessment of a reading (derived from what was done with it, never reported by the device). */
    public function quality(): string
    {
        return match ($this->disposition) {
            self::DISPOSITION_QUARANTINED => 'SUSPECT',
            self::DISPOSITION_LATE => 'LATE',
            self::DISPOSITION_HELD => 'UNCALIBRATED',
            default => 'OK',
        };
    }
}
