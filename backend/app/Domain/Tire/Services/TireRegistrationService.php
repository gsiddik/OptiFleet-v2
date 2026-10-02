<?php

namespace App\Domain\Tire\Services;

use App\Domain\ProductMaster\Models\Product;
use App\Domain\Tire\Models\Tire;
use Illuminate\Validation\ValidationException;

/**
 * Registers one physical, serial-numbered tire of a Tire product. The Product (Item Type = Tire)
 * is the source of truth for the tire's specification: any spec field the request leaves empty is
 * taken from the product's tire specification, so the user enters only what is unique to the
 * physical tire (serial number, DOT code, purchase data, location).
 */
class TireRegistrationService
{
    public function register(string $tenantId, array $attributes): Tire
    {
        $product = Product::query()->with(['tireSpec.singleLoadIndex', 'tireSpec.speedRating', 'tireSpec.plyRating'])->findOrFail($attributes['product_id']);
        if ($product->product_type !== 'TIRE') {
            throw ValidationException::withMessages(['product_id' => 'Tires can only be registered for a product whose Item Type is Tire.']);
        }

        $fromSpec = array_filter($this->specDefaults($product), fn ($value) => $value !== null);
        $given = array_filter($attributes, fn ($value) => $value !== null && $value !== '');

        return Tire::query()->create($given + $fromSpec + ['tenant_id' => $tenantId, 'current_status' => 'IN_STOCK']);
    }

    /** @return array<string, mixed> tire columns derived from the product's tire specification */
    private function specDefaults(Product $product): array
    {
        $spec = $product->tireSpec;
        if (! $spec) {
            return [];
        }

        return [
            'tire_size' => $spec->tire_size_computed,
            'pattern' => $spec->pattern_name,
            'construction_type' => in_array($spec->construction_type, ['RADIAL', 'BIAS'], true) ? $spec->construction_type : null,
            'tube_type' => match ($spec->tire_type) {
                'TUBELESS' => 'TUBELESS',
                'TUBE_TYPE', 'TUBE' => 'TUBE',
                default => null,
            },
            'section_width_mm' => $spec->width_mm,
            'aspect_ratio' => $spec->aspect_ratio_percent,
            'rim_diameter_inch' => $spec->rim_diameter_inch,
            'load_index' => $this->integerCode($spec->singleLoadIndex?->code),
            // Speed symbols are 1–2 characters (L, T, H, V…); anything else is not copied.
            'speed_rating' => strlen((string) $spec->speedRating?->code) <= 2 ? $spec->speedRating?->code : null,
            'ply_rating' => $this->integerCode($spec->plyRating?->code),
        ];
    }

    /** Lookup codes such as "152" or "16PR" → 152 / 16; anything else is left empty. */
    private function integerCode(?string $code): ?int
    {
        return $code !== null && preg_match('/^\s*(\d+)/', $code, $m) ? (int) $m[1] : null;
    }
}
