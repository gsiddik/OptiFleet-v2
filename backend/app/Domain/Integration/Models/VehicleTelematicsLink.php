<?php

namespace App\Domain\Integration\Models;

use App\Domain\Shared\Concerns\BelongsToTenant;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A telematics device feeding a vehicle's odometer. `odometer_offset_km` is
 * null until an admin calibrates it; GPS-distance readings are held until then.
 */
class VehicleTelematicsLink extends Model
{
    use BelongsToTenant, HasUuids;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_REVIEW = 'review';

    protected $fillable = [
        'tenant_id', 'vehicle_id', 'source', 'device_ref', 'odometer_offset_km', 'calibrated_by', 'calibrated_at',
        'status', 'review_reason', 'review_opened_at', 'review_reading_id', 'rebase_offset_km', 'baselines', 'last_measured_at', 'last_received_at',
    ];

    protected function casts(): array
    {
        return [
            'odometer_offset_km' => 'decimal:2', 'rebase_offset_km' => 'decimal:2', 'calibrated_at' => 'datetime',
            'review_opened_at' => 'datetime', 'last_measured_at' => 'datetime', 'last_received_at' => 'datetime', 'baselines' => 'array',
        ];
    }

    /**
     * Links of a device that has never reported a real odometer and has no offset yet: GPS distance is not an odometer until a
     * person calibrates it (readings are kept, never applied meanwhile).
     */
    public function scopeNeedsCalibration(Builder $query): Builder
    {
        $links = $this->getTable();
        $readings = (new VehicleOdometerReading)->getTable();

        return $query->whereNull("$links.odometer_offset_km")->whereNotExists(
            fn ($q) => $q->selectRaw('1')->from($readings)
                ->whereColumn("$readings.tenant_id", "$links.tenant_id")
                ->whereColumn("$readings.vehicle_id", "$links.vehicle_id")
                ->whereColumn("$readings.source", "$links.source")
                ->whereColumn("$readings.device_ref", "$links.device_ref")
                ->where("$readings.odometer_kind", VehicleOdometerReading::KIND_DEVICE)
        );
    }

    public function underReview(): bool
    {
        return $this->status === self::STATUS_REVIEW;
    }

    /** @return array{km: string, at: string}|null the last value accepted from this device for one kind, the next one is judged against it */
    public function baseline(string $kind): ?array
    {
        $b = ($this->baselines ?? [])[$kind] ?? null;

        return is_array($b) && isset($b['km'], $b['at']) ? $b : null;
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function isCalibrated(): bool
    {
        return $this->odometer_offset_km !== null;
    }
}
