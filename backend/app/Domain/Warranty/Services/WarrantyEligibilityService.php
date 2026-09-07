<?php

namespace App\Domain\Warranty\Services;

use App\Domain\Warranty\Models\Warranty;
use Carbon\Carbon;

/**
 * Section 40: pure, stateless eligibility check — no DB reads, no side
 * effects, no Eloquent model coupling (mirrors MaintenanceDueService's
 * shape from Phase 3, which takes primitives rather than a model so it's
 * trivially unit-testable without a database). A COMBINATION warranty
 * (e.g. "12 months OR 20,000 km") expires the moment ANY one of its
 * configured dimensions is exceeded, matching the brief's own worked
 * example (8 months / 22,000 km against a 12-month/20,000km warranty ->
 * EXPIRED, because the mileage dimension alone was exceeded).
 */
class WarrantyEligibilityService
{
    public function evaluate(
        string $coverageBasis,
        ?int $durationMonths,
        ?int $durationKm,
        ?int $durationEngineHours,
        int $toleranceDays,
        int $toleranceKm,
        int $toleranceEngineHours,
        Carbon $startsAt,
        ?float $startOdometer,
        ?float $startEngineHour,
        Carbon $currentDate,
        ?float $currentOdometer = null,
        ?float $currentEngineHour = null,
    ): string {
        $checks = [];

        if (in_array($coverageBasis, ['DATE', 'COMBINATION'], true) && $durationMonths !== null) {
            $expiryDate = $startsAt->copy()->addMonthsNoOverflow($durationMonths)->addDays($toleranceDays);
            $checks[] = $currentDate->greaterThan($expiryDate);
        }

        if (in_array($coverageBasis, ['MILEAGE', 'COMBINATION'], true) && $durationKm !== null && $currentOdometer !== null) {
            $expiryOdometer = ($startOdometer ?? 0) + $durationKm + $toleranceKm;
            $checks[] = $currentOdometer > $expiryOdometer;
        }

        if (in_array($coverageBasis, ['ENGINE_HOUR', 'COMBINATION'], true) && $durationEngineHours !== null && $currentEngineHour !== null) {
            $expiryEngineHour = ($startEngineHour ?? 0) + $durationEngineHours + $toleranceEngineHours;
            $checks[] = $currentEngineHour > $expiryEngineHour;
        }

        if (empty($checks)) {
            return 'ACTIVE';
        }

        return in_array(true, $checks, true) ? 'EXPIRED' : 'ACTIVE';
    }

    public function evaluateWarranty(Warranty $warranty, Carbon $currentDate, ?float $currentOdometer = null, ?float $currentEngineHour = null): string
    {
        return $this->evaluate(
            $warranty->coverage_basis,
            $warranty->duration_months,
            $warranty->duration_km,
            $warranty->duration_engine_hours,
            (int) $warranty->tolerance_days,
            (int) $warranty->tolerance_km,
            (int) $warranty->tolerance_engine_hours,
            Carbon::parse($warranty->starts_at),
            $warranty->start_odometer !== null ? (float) $warranty->start_odometer : null,
            $warranty->start_engine_hour !== null ? (float) $warranty->start_engine_hour : null,
            $currentDate,
            $currentOdometer,
            $currentEngineHour,
        );
    }
}
