<?php

namespace App\Domain\MasterData\Services;

use App\Domain\Audit\Services\AuditService;
use App\Domain\MasterData\Models\ComponentCategory;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\MasterData\Models\ComponentSubcategory;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Support\Messages;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single home for Component Classification rules (Component Group ->
 * Category -> Subcategory), shared by the tenant and platform master-data
 * endpoints and by Product create/edit:
 *
 *  - codes are unique per parent and owner, soft-deleted rows included
 *    (a platform code also blocks the same code in a tenant under that
 *    parent and vice versa, since the tenant sees both);
 *  - a used Category/Subcategory is never silently re-parented;
 *  - an Item Type still used by Products cannot be removed from a
 *    Subcategory's applicability;
 *  - new selections must be EFFECTIVELY active (self and every ancestor
 *    ACTIVE and not soft-deleted); children are never mutated when a parent
 *    is retired or restored;
 *  - a Product may keep an unchanged, since-retired classification;
 *  - Component Group + Category are mandatory for Sparepart / Consumable /
 *    Tire / Rim; Subcategory is optional for every Item Type.
 * Classification never touches an issued SKU (ProductSkuService reads the
 * Component Group abbreviation once, at creation).
 */
class ComponentClassificationService
{
    /** Owner decision: Component Group + Category mandatory for these Item Types; Subcategory always optional. */
    public const CATEGORY_REQUIRED_ITEM_TYPES = ['SPARE_PART', 'CONSUMABLE', 'TIRE', 'RIM'];

    public function __construct(private readonly AuditService $audit) {}

    // ------------------------------------------------------------------ categories

    public function createCategory(array $attributes, ?string $tenantId): ComponentCategory
    {
        return DB::transaction(function () use ($attributes, $tenantId) {
            $this->assertGroupSelectable($attributes['component_group_id'], $tenantId);
            $this->assertCategoryCodeAvailable($attributes['component_group_id'], $attributes['code'], $tenantId, null);
            $this->assertNameAvailable('component_categories', 'component_group_id', $attributes['component_group_id'], $attributes['name'], $tenantId, null);

            return ComponentCategory::query()->create($attributes + [
                'tenant_id' => $tenantId,
                'is_system' => $tenantId === null,
                'status' => 'ACTIVE',
            ]);
        });
    }

    public function updateCategory(ComponentCategory $category, array $attributes): ComponentCategory
    {
        return DB::transaction(function () use ($category, $attributes) {
            $category = ComponentCategory::query()->whereKey($category->id)->lockForUpdate()->firstOrFail();
            $parentId = $attributes['component_group_id'] ?? $category->component_group_id;

            if ($parentId !== $category->component_group_id) {
                if ($category->isUsedByProducts()) {
                    throw ValidationException::withMessages(['component_group_id' => 'This Category is already used by Products and cannot be moved to another Component Group. Reclassify the Products explicitly instead.']);
                }
                $this->assertGroupSelectable($parentId, $category->tenant_id);
                $this->assertCategoryCodeAvailable($parentId, $category->code, $category->tenant_id, $category->id);
            }
            if ($parentId !== $category->component_group_id || (isset($attributes['name']) && $attributes['name'] !== $category->name)) {
                $this->assertNameAvailable('component_categories', 'component_group_id', $parentId, $attributes['name'] ?? $category->name, $category->tenant_id, $category->id);
            }

            unset($attributes['code'], $attributes['tenant_id'], $attributes['is_system']);
            $category->update($attributes);

            return $category;
        });
    }

    // ------------------------------------------------------------------ subcategories

    public function createSubcategory(array $attributes, ?string $tenantId): ComponentSubcategory
    {
        return DB::transaction(function () use ($attributes, $tenantId) {
            $itemTypes = $attributes['item_types'] ?? [];
            unset($attributes['item_types'], $attributes['component_group_id']);

            $this->assertCategorySelectable($attributes['component_category_id'], $tenantId);
            $this->assertSubcategoryCodeAvailable($attributes['component_category_id'], $attributes['code'], $tenantId, null);
            $this->assertNameAvailable('component_subcategories', 'component_category_id', $attributes['component_category_id'], $attributes['name'], $tenantId, null);

            $subcategory = ComponentSubcategory::query()->create($attributes + [
                'tenant_id' => $tenantId,
                'is_system' => $tenantId === null,
                'status' => 'ACTIVE',
            ]);
            $subcategory->syncItemTypes($itemTypes);
            if ($itemTypes !== []) {
                $this->audit->log('ComponentSubcategory', $subcategory->id, 'item_types_changed', ['item_types' => []], ['item_types' => $subcategory->itemTypes()], $tenantId);
            }

            return $subcategory;
        });
    }

    public function updateSubcategory(ComponentSubcategory $subcategory, array $attributes): ComponentSubcategory
    {
        return DB::transaction(function () use ($subcategory, $attributes) {
            $subcategory = ComponentSubcategory::query()->whereKey($subcategory->id)->lockForUpdate()->firstOrFail();
            $parentId = $attributes['component_category_id'] ?? $subcategory->component_category_id;

            if ($parentId !== $subcategory->component_category_id) {
                if ($subcategory->isUsedByProducts()) {
                    throw ValidationException::withMessages(['component_category_id' => 'This Subcategory is already used by Products and cannot be moved to another Category. Reclassify the Products explicitly instead.']);
                }
                $this->assertCategorySelectable($parentId, $subcategory->tenant_id);
                $this->assertSubcategoryCodeAvailable($parentId, $subcategory->code, $subcategory->tenant_id, $subcategory->id);
            }
            if ($parentId !== $subcategory->component_category_id || (isset($attributes['name']) && $attributes['name'] !== $subcategory->name)) {
                $this->assertNameAvailable('component_subcategories', 'component_category_id', $parentId, $attributes['name'] ?? $subcategory->name, $subcategory->tenant_id, $subcategory->id);
            }

            if (array_key_exists('item_types', $attributes)) {
                $this->changeItemTypes($subcategory, array_values(array_unique($attributes['item_types'] ?? [])));
            }

            unset($attributes['code'], $attributes['tenant_id'], $attributes['is_system'], $attributes['item_types'], $attributes['component_group_id']);
            $subcategory->update($attributes);

            return $subcategory;
        });
    }

    private function changeItemTypes(ComponentSubcategory $subcategory, array $itemTypes): void
    {
        $before = $subcategory->itemTypes();
        sort($itemTypes);
        if ($before === $itemTypes) {
            return;
        }

        // Narrowing applicability must not strand existing Products (an empty set = unrestricted).
        if ($itemTypes !== []) {
            $stranded = DB::table('products')
                ->where('component_subcategory_id', $subcategory->id)
                ->whereNotIn('product_type', $itemTypes)
                ->distinct()
                ->pluck('product_type')
                ->all();
            if ($stranded !== []) {
                throw ValidationException::withMessages(['item_types' => Messages::text('validation.masterData.itemTypesStranded', ['itemTypes' => implode(', ', $stranded)])]);
            }
        }

        $subcategory->syncItemTypes($itemTypes);
        $this->audit->log('ComponentSubcategory', $subcategory->id, 'item_types_changed', ['item_types' => $before], ['item_types' => $itemTypes], $subcategory->tenant_id);
    }

    // ------------------------------------------------------------------ lifecycle (both levels)

    /**
     * Soft delete only. Descendants are NOT touched: they become unavailable
     * for new data through effective availability and stay individually
     * restorable; Products keep resolving every level.
     */
    public function delete(ComponentCategory|ComponentSubcategory $row): void
    {
        DB::transaction(function () use ($row) {
            $row = $row::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            $row->update(['status' => 'INACTIVE']);
            $row->delete();
        });
    }

    /** Restores only this row — a child the user deleted itself stays deleted. */
    public function restore(ComponentCategory|ComponentSubcategory $row): ComponentCategory|ComponentSubcategory
    {
        return DB::transaction(function () use ($row) {
            $row = $row::withTrashed()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            abort_unless($row->trashed(), 422, 'This record is not deleted.');

            $row->status = 'ACTIVE';
            $row->restore();
            $this->audit->log(class_basename($row), $row->id, 'restored', null, ['code' => $row->code, 'name' => $row->name], $row->tenant_id);

            return $row;
        });
    }

    // ------------------------------------------------------------------ product classification

    /**
     * Validate a Product's (new or changed) mechanical classification and
     * return the three columns to persist. On edit, `$input` holds only the
     * keys the caller sent; an unchanged value may reference a since-retired
     * master row, a changed one must be effectively active. Item Type
     * applicability is enforced whenever the Subcategory is (newly) chosen.
     *
     * @return array{component_group_id: ?string, component_category_id: ?string, component_subcategory_id: ?string}
     */
    public function resolveProductClassification(string $productType, array $input, ?Product $product = null): array
    {
        $keys = ['component_group_id', 'component_category_id', 'component_subcategory_id'];
        $current = [];
        foreach ($keys as $key) {
            $current[$key] = $product?->getAttribute($key);
        }
        $target = [];
        foreach ($keys as $key) {
            $target[$key] = array_key_exists($key, $input) ? ($input[$key] ?: null) : $current[$key];
        }
        if ($product !== null && $target === $current) {
            return $target;
        }

        [$groupId, $categoryId, $subcategoryId] = array_values($target);
        $errors = [];

        // Mandatory Category for new Products of these Item Types, and it can never be
        // cleared once set; a legacy unclassified Product is only held to it once its
        // classification is actually edited (no destructive backfill).
        if ($categoryId === null && in_array($productType, self::CATEGORY_REQUIRED_ITEM_TYPES, true)) {
            if ($groupId === null) {
                $errors['component_group_id'] = "A Component Group is required for Item Type {$productType}.";
            }
            $errors['component_category_id'] = "A Component Category is required for Item Type {$productType}.";
            throw ValidationException::withMessages($errors);
        }

        if ($subcategoryId !== null && $categoryId === null) {
            $errors['component_category_id'] = 'A Category is required when a Subcategory is selected.';
        }
        if ($categoryId !== null && $groupId === null) {
            $errors['component_group_id'] = 'A Component Group is required when a Category is selected.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($groupId !== null) {
            $changed = $groupId !== $current['component_group_id'];
            $query = ComponentGroup::query()->whereKey($groupId);
            $exists = $changed
                ? (clone $query)->whereNull('deleted_at')->where('status', 'ACTIVE')->exists()
                : $query->withTrashed()->exists();
            if (! $exists) {
                $errors['component_group_id'] = 'The selected Component Group is not available.';
            }
        }

        $category = $categoryId === null ? null : ComponentCategory::withTrashed()->find($categoryId);
        if ($categoryId !== null) {
            if ($category === null) {
                $errors['component_category_id'] = 'The selected Category is not available.';
            } elseif ($category->component_group_id !== $groupId) {
                $errors['component_category_id'] = 'The selected Category does not belong to the selected Component Group.';
            } elseif ($categoryId !== $current['component_category_id'] && ! ComponentCategory::query()->whereKey($categoryId)->effectivelyActive()->exists()) {
                $errors['component_category_id'] = 'The selected Category (or its Component Group) is inactive or deleted.';
            }
        }

        $subcategory = $subcategoryId === null ? null : ComponentSubcategory::withTrashed()->find($subcategoryId);
        if ($subcategoryId !== null) {
            if ($subcategory === null) {
                $errors['component_subcategory_id'] = 'The selected Subcategory is not available.';
            } elseif ($subcategory->component_category_id !== $categoryId) {
                $errors['component_subcategory_id'] = 'The selected Subcategory does not belong to the selected Category.';
            } elseif ($subcategoryId !== $current['component_subcategory_id']) {
                if (! ComponentSubcategory::query()->whereKey($subcategoryId)->effectivelyActive()->exists()) {
                    $errors['component_subcategory_id'] = 'The selected Subcategory (or its Category / Component Group) is inactive or deleted.';
                } elseif (! $subcategory->allowsItemType($productType)) {
                    $errors['component_subcategory_id'] = "The selected Subcategory is not applicable to Item Type {$productType} (allowed: ".implode(', ', $subcategory->itemTypes()).').';
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $target;
    }

    // ------------------------------------------------------------------ guards

    private function assertGroupSelectable(string $groupId, ?string $tenantId): void
    {
        $ok = DB::table('component_groups')->where('id', $groupId)->whereNull('deleted_at')->where('status', 'ACTIVE')
            ->where(fn ($q) => $tenantId === null ? $q->whereNull('tenant_id') : $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->exists();
        if (! $ok) {
            throw ValidationException::withMessages(['component_group_id' => 'The selected Component Group is not available (inactive, deleted, or not visible).']);
        }
    }

    private function assertCategorySelectable(string $categoryId, ?string $tenantId): void
    {
        $ok = ComponentCategory::query()->whereKey($categoryId)->effectivelyActive()
            ->where(fn ($q) => $tenantId === null ? $q->whereNull('component_categories.tenant_id') : $q->whereNull('component_categories.tenant_id')->orWhere('component_categories.tenant_id', $tenantId))
            ->exists();
        if (! $ok) {
            throw ValidationException::withMessages(['component_category_id' => 'The selected Category is not available (inactive, deleted, its Component Group is retired, or not visible).']);
        }
    }

    private function assertCategoryCodeAvailable(string $groupId, string $code, ?string $tenantId, ?string $exceptId): void
    {
        $this->assertCodeAvailable('component_categories', 'component_group_id', $groupId, $code, $tenantId, $exceptId);
    }

    private function assertSubcategoryCodeAvailable(string $categoryId, string $code, ?string $tenantId, ?string $exceptId): void
    {
        $this->assertCodeAvailable('component_subcategories', 'component_category_id', $categoryId, $code, $tenantId, $exceptId);
    }

    private function assertCodeAvailable(string $table, string $parentColumn, string $parentId, string $code, ?string $tenantId, ?string $exceptId): void
    {
        DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', ["{$table}:{$parentId}:{$code}"]);

        $taken = DB::table($table)
            ->where($parentColumn, $parentId)
            ->where('code', $code)
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->when($tenantId !== null, fn ($q) => $q->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id')))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['code' => "Code \"{$code}\" is already used under this parent (deleted records included)."]);
        }
    }

    private function assertNameAvailable(string $table, string $parentColumn, string $parentId, string $name, ?string $tenantId, ?string $exceptId): void
    {
        $taken = DB::table($table)
            ->where($parentColumn, $parentId)
            ->whereNull('deleted_at')
            ->whereRaw('LOWER(name) = LOWER(?)', [$name])
            ->when($exceptId, fn ($q) => $q->where('id', '!=', $exceptId))
            ->where(fn ($q) => $tenantId === null ? $q->whereNull('tenant_id') : $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->exists();
        if ($taken) {
            throw ValidationException::withMessages(['name' => "\"{$name}\" already exists under this parent."]);
        }
    }
}
