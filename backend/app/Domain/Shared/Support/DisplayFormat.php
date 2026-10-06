<?php

namespace App\Domain\Shared\Support;

use App\Domain\Pricing\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Throwable;

/**
 * Presentation standard for printed documents (the frontend mirrors it in utils/money.ts and
 * utils/quantity.ts): money always has exactly two decimals, rounded half-up like Money, with
 * thousands separators; quantities drop meaningless trailing zeros ("5.0000" -> "5"). Exact
 * decimal arithmetic only — never floats.
 *
 * Every formatter takes the document locale (owner decision D3) and only changes the presentation:
 * `en` "1,234.56" / "October 6, 2026", `id` "1.234,56" / "6 Oktober 2026". Stored values are never
 * localized; a document is formatted when it is rendered.
 */
final class DisplayFormat
{
    public static function money(string|int|float|null $amount, string $locale = 'en'): ?string
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

        return ($negative ? '-' : '').self::group($int, $locale).self::decimalSeparator($locale).$fraction;
    }

    public static function quantity(string|int|float|null $quantity, string $locale = 'en'): ?string
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

        return ($value->isNegative() ? '-' : '').self::group($parts[0], $locale).(isset($parts[1]) ? self::decimalSeparator($locale).$parts[1] : '');
    }

    /** Calendar date: `en` "October 6, 2026", `id` "6 Oktober 2026". */
    public static function date(CarbonInterface|string|null $value, string $locale = 'en'): ?string
    {
        $date = self::carbon($value);

        return $date?->locale(self::carbonLocale($locale))->isoFormat($locale === 'id' ? 'D MMMM YYYY' : 'MMMM D, YYYY');
    }

    /** Date and 24-hour time: `en` "October 6, 2026 14:05", `id` "6 Oktober 2026 14:05". */
    public static function dateTime(CarbonInterface|string|null $value, string $locale = 'en'): ?string
    {
        $date = self::carbon($value);

        return $date ? self::date($date, $locale).' '.$date->format('H:i') : null;
    }

    private static function carbon(CarbonInterface|string|null $value): ?CarbonInterface
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof CarbonInterface) {
            return $value;
        }
        try {
            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            return null;
        }
    }

    private static function carbonLocale(string $locale): string
    {
        return $locale === 'id' ? 'id' : 'en';
    }

    private static function decimalSeparator(string $locale): string
    {
        return $locale === 'id' ? ',' : '.';
    }

    private static function group(string $digits, string $locale = 'en'): string
    {
        $separator = $locale === 'id' ? '.' : ',';

        return ltrim(strrev(implode($separator, str_split(strrev($digits), 3))), $separator);
    }
}
