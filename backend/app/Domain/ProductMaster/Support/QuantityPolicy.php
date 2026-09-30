<?php

namespace App\Domain\ProductMaster\Support;

use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\Uom;
use Illuminate\Validation\ValidationException;

/**
 * Owner decision (discrete quantity rule): every product quantity is a whole number of items,
 * except products whose Unit of Measure is a measured unit (Type of Measure Capacity / Weight /
 * Length — e.g. Liter, Kg), which may use fractions. Enforced in the domain (inventory engine and
 * every document-line service), never only in the UI. Stored history is never rounded.
 */
final class QuantityPolicy
{
    public const MEASURED_TYPES = ['CAPACITY', 'WEIGHT', 'LENGTH'];

    /** Platform baseline measured units, recognised even while their Type of Measure is unset. */
    public const MEASURED_CODES = ['LTR', 'KG'];

    public static function uomAllowsFraction(?Uom $uom): bool
    {
        if (! $uom) {
            return false;
        }

        return in_array($uom->measure_type, self::MEASURED_TYPES, true)
            || in_array(strtoupper((string) $uom->code), self::MEASURED_CODES, true);
    }

    public static function allowsFraction(Product $product): bool
    {
        return self::uomAllowsFraction($product->relationLoaded('uom') ? $product->uom : $product->uom()->withoutGlobalScopes()->first());
    }

    /**
     * @throws ValidationException when a discrete (counted) product is given a fractional quantity
     */
    public static function assertValid(Product $product, string|int|float|null $quantity, string $field = 'quantity'): void
    {
        if ($quantity === null || $quantity === '' || self::isWhole($quantity) || self::allowsFraction($product)) {
            return;
        }

        throw ValidationException::withMessages([
            $field => "\"{$product->name}\" is counted in whole units — the quantity must be a whole number.",
        ]);
    }

    /** Same check for a line that only carries a product id (the line's own validation reports an unknown product). */
    public static function assertValidForProductId(?string $productId, string|int|float|null $quantity, string $field = 'quantity'): void
    {
        $product = $productId ? Product::query()->withoutGlobalScopes()->find($productId) : null;
        if ($product) {
            self::assertValid($product, $quantity, $field);
        }
    }

    public static function isWhole(string|int|float $quantity): bool
    {
        $value = (string) $quantity;
        if (str_contains($value, 'E') || str_contains($value, 'e')) {
            $value = rtrim(rtrim(number_format((float) $quantity, 8, '.', ''), '0'), '.');
        }
        if (! str_contains($value, '.')) {
            return true;
        }

        return rtrim(substr($value, strpos($value, '.') + 1), '0') === '';
    }
}
