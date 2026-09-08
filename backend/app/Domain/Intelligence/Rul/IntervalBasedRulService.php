<?php

namespace App\Domain\Intelligence\Rul;

/**
 * Phase 7 Section 24-25: Remaining Useful Life, fallback flavor.
 * INTERVAL_BASED = (next scheduled/expected point) - (current usage),
 * never a machine-learned estimate. Every point estimate is returned as
 * a range (Section 24: "prefer ranges... do not output fake precision")
 * using config('intelligence.rul.uncertainty_band_ratio') when no
 * statistically-derived spread is available.
 */
class IntervalBasedRulService
{
    public const BASIS = 'INTERVAL_BASED';

    /** @return array|null null when the vehicle has no maintenance schedule to project from. */
    public function forVehicle(array $vehicleFeatures): ?array
    {
        $remainingKm = $vehicleFeatures['nearest_due_odometer_remaining'] ?? null;
        $remainingDays = $vehicleFeatures['nearest_due_date_days_remaining'] ?? null;

        if ($remainingKm === null && $remainingDays === null) {
            return null;
        }

        return [
            'basis' => self::BASIS,
            'remaining_km' => $remainingKm !== null ? $this->range((float) $remainingKm) : null,
            'remaining_days' => $remainingDays !== null ? $this->range((float) $remainingDays) : null,
            'urgency' => $this->urgency($remainingDays !== null ? (float) $remainingDays : null),
            'explanation' => ['Based on the vehicle\'s nearest scheduled maintenance due point (odometer/date), not a machine-learned estimate.'],
        ];
    }

    public function forTire(array $tireFeatures): array
    {
        $expectedLifeKm = (float) config('intelligence.rul.expected_life_km.tire', 60000);
        $usageKm = (float) ($tireFeatures['usage_km'] ?? 0);
        $remainingKm = max(0, $expectedLifeKm - $usageKm);

        return [
            'basis' => self::BASIS,
            'remaining_km' => $this->range($remainingKm),
            'remaining_days' => null,
            'urgency' => $this->urgencyFromKm($remainingKm, $expectedLifeKm),
            'explanation' => ["Based on an assumed typical tire service life of {$expectedLifeKm}km minus {$usageKm}km already used — not a measured wear reading."],
        ];
    }

    private function range(float $point): array
    {
        $band = (float) config('intelligence.rul.uncertainty_band_ratio', 0.25);
        $spread = abs($point) * $band;

        return [
            'point' => round($point, 1),
            'low' => round($point - $spread, 1),
            'high' => round($point + $spread, 1),
        ];
    }

    private function urgency(?float $remainingDays): string
    {
        if ($remainingDays === null) {
            return 'UNKNOWN';
        }
        if ($remainingDays <= 0) {
            return 'CRITICAL';
        }

        $thresholds = config('intelligence.rul.urgency_days_thresholds');
        foreach ($thresholds as $level => $max) {
            if ($remainingDays <= $max) {
                return $level;
            }
        }

        return 'LOW';
    }

    private function urgencyFromKm(float $remainingKm, float $expectedLifeKm): string
    {
        $ratio = $expectedLifeKm > 0 ? $remainingKm / $expectedLifeKm : 1.0;
        if ($ratio <= 0.05) {
            return 'CRITICAL';
        }
        if ($ratio <= 0.15) {
            return 'HIGH';
        }
        if ($ratio <= 0.35) {
            return 'MEDIUM';
        }

        return 'LOW';
    }
}
