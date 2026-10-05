<?php

namespace App\Domain\Tire\Services;

use Carbon\CarbonImmutable;

/**
 * The single source of truth for a tire's age. The Manufacture Date Code is the DOT date code
 * "WWYY" (production week, two-digit year — e.g. "1225" = week 12 of 2025); the age is the number
 * of whole months from the Monday of that ISO week until now. The frontend never computes it.
 */
class TireAgeService
{
    public const FORMAT_MESSAGE = 'Enter the DOT date code as 4 digits WWYY (production week 01–53 and two-digit year), e.g. 1225 = week 12 of 2025, not in the future.';

    /** "WWYY" → the Monday of that ISO week; anything else (or a future week) is unknown. */
    public function manufactureDate(?string $code, ?CarbonImmutable $now = null): ?CarbonImmutable
    {
        if ($code === null || ! preg_match('/^\s*(\d{2})(\d{2})\s*$/', $code, $m)) {
            return null;
        }
        $now ??= CarbonImmutable::now();
        [$week, $year] = [(int) $m[1], 2000 + (int) $m[2]];
        if ($week < 1 || $week > 53 || $year > (int) $now->format('Y')) {
            return null;
        }
        $date = $now->setISODate($year, $week)->startOfDay();
        // Week 53 only exists in some years (setISODate rolls it into the next year), and a week
        // that has not started yet cannot be a production date.
        if ((int) $date->format('o') !== $year || $date->greaterThan($now)) {
            return null;
        }

        return $date;
    }

    public function isValidCode(?string $code, ?CarbonImmutable $now = null): bool
    {
        return $this->manufactureDate($code, $now) !== null;
    }

    /** Whole months since manufacture, or null when the code is missing / not a valid DOT code. */
    public function ageMonths(?string $code, ?CarbonImmutable $now = null): ?int
    {
        $now ??= CarbonImmutable::now();
        $manufactured = $this->manufactureDate($code, $now);

        return $manufactured ? (int) floor($manufactured->diffInMonths($now)) : null;
    }
}
