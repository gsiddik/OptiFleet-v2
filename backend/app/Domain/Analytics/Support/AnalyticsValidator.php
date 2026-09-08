<?php

namespace App\Domain\Analytics\Support;

/**
 * Common data-quality checks (Phase 6 Section 16) shared across
 * extractors. Extractors call these before building a document; a
 * failure means recordError() + skip that row, never a written document
 * with impossible values.
 */
class AnalyticsValidator
{
    public static function isNonNegativeDuration(mixed $minutes): bool
    {
        return is_numeric($minutes) && (float) $minutes >= 0;
    }

    public static function isValidMonetaryValue(mixed $amount): bool
    {
        if (! is_numeric($amount)) {
            return false;
        }
        $value = (float) $amount;

        return is_finite($value) && $value >= 0;
    }

    public static function isValidQuantity(mixed $quantity): bool
    {
        return is_numeric($quantity) && is_finite((float) $quantity);
    }

    /** Guards cost-per-unit divisions (Section 34) against invalid denominators. */
    public static function safeDivide(float $numerator, ?float $denominator): ?float
    {
        if ($denominator === null || $denominator <= 0) {
            return null;
        }

        return $numerator / $denominator;
    }
}
