<?php

namespace App\Domain\ProductMaster\Services;

use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\ProductCompatibility;
use App\Domain\Vehicle\Models\Vehicle;
use Illuminate\Support\Collection;

/**
 * Section 2/42: resolves which Products fit a given Vehicle (optionally
 * narrowed to one diagnosis Component Group), most-specific compatibility
 * rule first. A Product with no compatibility rows at all is treated as
 * universally compatible (e.g. a generic consumable) — narrower rules only
 * ever add precedence among products that DO declare compatibility, never
 * exclude a product that declares none.
 */
class ProductCompatibilityService
{
    public function compatibleProducts(Vehicle $vehicle, ?string $componentGroupId = null): Collection
    {
        $rules = ProductCompatibility::query()
            ->where(function ($q) use ($vehicle) {
                $q->whereNull('vehicle_category_id')->orWhere('vehicle_category_id', $vehicle->vehicle_category_id);
            })
            ->where(function ($q) use ($vehicle) {
                $q->whereNull('vehicle_brand')->orWhere('vehicle_brand', $vehicle->brand);
            })
            ->where(function ($q) use ($vehicle) {
                $q->whereNull('vehicle_model')->orWhere('vehicle_model', $vehicle->model);
            })
            ->when($componentGroupId, fn ($q) => $q->where(function ($q2) use ($componentGroupId) {
                $q2->whereNull('component_group_id')->orWhere('component_group_id', $componentGroupId);
            }))
            ->with('product.category', 'product.uom')
            ->get()
            ->sortByDesc(fn (ProductCompatibility $rule) => $rule->specificity());

        $productsWithRules = $rules->pluck('product')->unique('id')->filter(fn ($p) => $p && $p->status === 'ACTIVE');

        $universal = Product::query()->where('status', 'ACTIVE')->whereDoesntHave('compatibilities')
            ->when($componentGroupId, fn ($q) => $q->where(function ($q2) use ($componentGroupId) {
                $q2->whereHas('componentGroups', fn ($q3) => $q3->where('component_groups.id', $componentGroupId))
                    ->orWhereDoesntHave('componentGroups');
            }))
            ->get();

        return $productsWithRules->merge($universal)->unique('id')->values();
    }
}
