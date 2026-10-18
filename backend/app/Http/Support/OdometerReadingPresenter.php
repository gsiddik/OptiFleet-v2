<?php

namespace App\Http\Support;

use App\Domain\Integration\Models\VehicleOdometerReading;

/**
 * One odometer ledger row for the API. Times are explicit: `measured_at` is when the value was true, `received_at` when OptiFleet
 * stored it; `recorded_at` is kept as an alias of `measured_at` for older clients. Kilometres are decimal strings.
 */
final class OdometerReadingPresenter
{
    /** @return array<string, mixed> */
    public static function present(VehicleOdometerReading $r, bool $withPeople = true): array
    {
        $measured = $r->recorded_at?->toIso8601String();

        return [
            'id' => $r->id,
            'vehicle_id' => $r->vehicle_id,
            'source' => $r->source,
            'origin' => $r->origin,
            'kind' => $r->odometer_kind,
            'device_ref' => $r->device_ref,
            'reported_km' => $r->reported_km,
            'effective_km' => $r->effective_km,
            'previous_odometer' => $r->previous_odometer,
            'unit' => $r->unit ?? 'km',
            'applied' => $r->applied,
            'disposition' => $r->disposition,
            'quality' => $r->quality(),
            'anomaly_code' => $r->anomaly_code,
            'review_status' => $r->review_status,
            'measured_at' => $measured,
            'recorded_at' => $measured,
            'received_at' => $r->received_at?->toIso8601String(),
            'note' => $r->note,
            'reference_type' => $r->reference_type,
            'reference_id' => $r->reference_id,
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'review_note' => $r->review_note,
        ] + ($withPeople ? [
            'entered_by' => $r->enteredBy ? ['id' => $r->enteredBy->id, 'name' => $r->enteredBy->name] : null,
            'reviewed_by' => $r->reviewedBy ? ['id' => $r->reviewedBy->id, 'name' => $r->reviewedBy->name] : null,
        ] : []);
    }
}
