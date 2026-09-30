<?php

namespace App\Domain\Shared\Support;

use App\Domain\Pricing\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;

/**
 * Presentation standard for printed documents (the frontend mirrors it in utils/money.ts and
 * utils/quantity.ts): money always has exactly two decimals, rounded half-up like Money, with
 * thousands separators; quantities drop meaningless trailing zeros ("5.0000" -> "5"). Exact
 * decimal arithmetic only — never floats.
 */
final class DisplayFormat
{
    public static function money(string|int|float|null $amount): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }
        try {
            $value = Money::of($amount);
        } catch (MathException) {
            return (string) $amount;
        }
        $negative = $value->isNegative() && ! $value->isZero();
        [$int, $fraction] = explode('.', (string) $value->abs());

        return ($negative ? '-' : '').self::group($int).'.'.$fraction;
    }

    public static function quantity(string|int|float|null $quantity): ?string
    {
        if ($quantity === null || $quantity === '') {
            return null;
        }
        try {
            $value = BigDecimal::of((string) $quantity)->strippedOfTrailingZeros();
        } catch (MathException) {
            return (string) $quantity;
        }
        $parts = explode('.', (string) $value->abs());

        return ($value->isNegative() ? '-' : '').self::group($parts[0]).(isset($parts[1]) ? '.'.$parts[1] : '');
    }

    private static function group(string $digits): string
    {
        return ltrim(strrev(implode(',', str_split(strrev($digits), 3))), ',');
    }
}
