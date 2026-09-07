<?php

namespace App\Domain\Pricing\Support;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * Deterministic, non-floating-point money arithmetic. Wraps brick/math
 * BigDecimal (works with or without the bcmath PHP extension — it falls
 * back to a pure-PHP calculator) so every commercial calculation in Phase 2
 * uses exact decimal arithmetic with explicit, documented rounding, never
 * PHP floats.
 *
 * All amounts are scale-2 (cents-level precision), rounded half-up.
 */
final class Money
{
    private const SCALE = 2;

    public static function of(string|int|float $amount): BigDecimal
    {
        return BigDecimal::of((string) $amount)->toScale(self::SCALE, RoundingMode::HALF_UP);
    }

    public static function add(string|int|float ...$amounts): string
    {
        $total = BigDecimal::of('0');
        foreach ($amounts as $amount) {
            $total = $total->plus(self::of($amount));
        }

        return (string) $total->toScale(self::SCALE, RoundingMode::HALF_UP);
    }

    public static function subtract(string|int|float $a, string|int|float $b): string
    {
        return (string) self::of($a)->minus(self::of($b))->toScale(self::SCALE, RoundingMode::HALF_UP);
    }

    public static function multiply(string|int|float $a, string|int|float $b): string
    {
        return (string) self::of($a)->multipliedBy(BigDecimal::of((string) $b))->toScale(self::SCALE, RoundingMode::HALF_UP);
    }

    /**
     * Percentage of an amount, e.g. percentageOf(1000000, 11) => "110000.00"
     * for an 11% tax rate.
     */
    public static function percentageOf(string|int|float $amount, string|int|float $ratePercent): string
    {
        return (string) self::of($amount)
            ->multipliedBy(BigDecimal::of((string) $ratePercent))
            ->dividedBy(100, self::SCALE, RoundingMode::HALF_UP);
    }

    public static function compare(string|int|float $a, string|int|float $b): int
    {
        return self::of($a)->compareTo(self::of($b));
    }

    public static function isZeroOrLess(string|int|float $a): bool
    {
        return self::compare($a, '0') <= 0;
    }

    public static function isGreaterThan(string|int|float $a, string|int|float $b): bool
    {
        return self::compare($a, $b) > 0;
    }

    public static function max(string|int|float $a, string|int|float $b): string
    {
        return self::compare($a, $b) >= 0 ? self::of($a)->__toString() : self::of($b)->__toString();
    }

    /**
     * Documented proration formula: unit_price * remaining_days / total_days
     * in the billing period, both inclusive day counts. Deterministic
     * rounding to 2 decimal places, half-up.
     */
    public static function prorate(string|int|float $amount, int $remainingDays, int $totalDays): string
    {
        if ($totalDays <= 0) {
            return '0.00';
        }

        return (string) self::of($amount)
            ->multipliedBy($remainingDays)
            ->dividedBy($totalDays, self::SCALE, RoundingMode::HALF_UP);
    }
}
