<?php

namespace App\Domain\MasterData\Support;

use App\Domain\MasterData\Models\ComponentCategory;
use App\Domain\MasterData\Models\ComponentSubcategory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * List query + presentation for Component Categories / Subcategories, shared
 * by the tenant and platform controllers. Every row carries `is_used`
 * (Product usage) and `is_deleted`; subcategories also carry `item_types`
 * (empty = unrestricted). Parents are loaded withTrashed so a row under a
 * retired parent still shows where it lives.
 */
final class ComponentClassificationListing
{
    private const SORTABLE = ['sequence', 'code', 'name', 'status', 'updated_at'];

    public static function categories(Builder $query, Request $request): Builder
    {
        $query->withUsage()->with('componentGroup:id,code,name,abbreviation,status,deleted_at');
        self::common($query, $request, 'component_categories');

        if ($groupId = $request->string('component_group_id')->value()) {
            $query->where('component_categories.component_group_id', $groupId);
        }

        return $query->orderBy('component_categories.sequence')->orderBy('component_categories.name');
    }

    public static function subcategories(Builder $query, Request $request): Builder
    {
        $query->withUsage()->with('category:id,component_group_id,code,name,status,deleted_at', 'category.componentGroup:id,code,name,abbreviation,status,deleted_at');
        self::common($query, $request, 'component_subcategories');

        if ($categoryId = $request->string('component_category_id')->value()) {
            $query->where('component_subcategories.component_category_id', $categoryId);
        }
        if ($groupId = $request->string('component_group_id')->value()) {
            $query->whereIn('component_subcategories.component_category_id', DB::table('component_categories')->select('id')->where('component_group_id', $groupId));
        }
        if ($itemType = $request->string('item_type')->value()) {
            $query->whereExists(fn ($q) => $q->from('component_subcategory_item_types as fit')
                ->whereColumn('fit.component_subcategory_id', 'component_subcategories.id')
                ->where('fit.item_type', $itemType));
        }

        return $query->orderBy('component_subcategories.sequence')->orderBy('component_subcategories.name');
    }

    private static function common(Builder $query, Request $request, string $table): void
    {
        match ($request->string('trashed')->value()) {
            'with' => $query->withTrashed(),
            'only' => $query->onlyTrashed(),
            default => null,
        };
        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where("{$table}.name", 'ilike', "%{$search}%")->orWhere("{$table}.code", 'ilike', "%{$search}%"));
        }
        if ($status = $request->string('status')->value()) {
            $query->where("{$table}.status", $status);
        }
        if ($request->has('is_system')) {
            $query->where("{$table}.is_system", $request->boolean('is_system'));
        }
        $sort = $request->string('sort')->value();
        if (in_array($sort, self::SORTABLE, true)) {
            $query->orderBy("{$table}.{$sort}", $request->string('direction')->lower()->value() === 'desc' ? 'desc' : 'asc');
        }
    }

    public static function presentCategory(ComponentCategory $category): ComponentCategory
    {
        $used = $category->getAttribute('is_used') ?? $category->isUsedByProducts();
        $category->setAttribute('is_used', (bool) $used);
        $category->setAttribute('is_deleted', $category->trashed());

        return $category;
    }

    /** Item types for a whole page in one query. */
    public static function presentSubcategories(LengthAwarePaginator $page): LengthAwarePaginator
    {
        $types = DB::table('component_subcategory_item_types')
            ->whereIn('component_subcategory_id', $page->getCollection()->pluck('id'))
            ->orderBy('item_type')
            ->get()
            ->groupBy('component_subcategory_id');

        $page->getCollection()->each(fn (ComponentSubcategory $s) => self::presentSubcategory($s, $types->get($s->id)?->pluck('item_type')->all() ?? []));

        return $page;
    }

    public static function presentSubcategory(ComponentSubcategory $subcategory, ?array $itemTypes = null): ComponentSubcategory
    {
        $used = $subcategory->getAttribute('is_used') ?? $subcategory->isUsedByProducts();
        $subcategory->setAttribute('is_used', (bool) $used);
        $subcategory->setAttribute('is_deleted', $subcategory->trashed());
        $subcategory->setAttribute('item_types', $itemTypes ?? $subcategory->itemTypes());

        return $subcategory;
    }
}
