<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\MasterData\Models\ComponentCategory;
use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\MasterData\Models\ComponentSubcategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Lightweight cascading lookups for the Product form / Product filters
 * (Component Group -> Category -> Subcategory), gated by product.view so a
 * product editor needs no master-data permission. New-data lookups return
 * only EFFECTIVELY active rows; `include_inactive=1` (filters) also returns
 * retired rows, flagged, so historical Products can still be found.
 */
class ProductClassificationController extends Controller
{
    public function groups(Request $request)
    {
        $query = ComponentGroup::query()->select(['id', 'code', 'name', 'abbreviation', 'parent_id', 'sequence', 'status', 'deleted_at']);
        if ($request->boolean('include_inactive')) {
            $query->withTrashed();
        } else {
            $query->where('status', 'ACTIVE');
        }

        return $this->ok($query->orderBy('sequence')->orderBy('name')->get());
    }

    public function categories(Request $request)
    {
        $request->validate(['component_group_id' => ['required', 'uuid']]);
        $query = ComponentCategory::query()
            ->select(['id', 'component_group_id', 'code', 'name', 'sequence', 'status', 'deleted_at'])
            ->where('component_group_id', $request->input('component_group_id'));
        $request->boolean('include_inactive') ? $query->withTrashed() : $query->effectivelyActive();

        return $this->ok($query->orderBy('sequence')->orderBy('name')->get());
    }

    /**
     * Each row carries `item_types` (empty = unrestricted) and, when
     * `item_type` is given, `allowed` so the form can disable incompatible
     * Subcategories instead of hiding them.
     */
    public function subcategories(Request $request)
    {
        $request->validate([
            'component_category_id' => ['required', 'uuid'],
            'item_type' => ['nullable', 'in:'.implode(',', ComponentSubcategory::ITEM_TYPES).',OTHER'],
        ]);
        $query = ComponentSubcategory::query()
            ->select(['id', 'component_category_id', 'code', 'name', 'description', 'sequence', 'status', 'deleted_at'])
            ->where('component_category_id', $request->input('component_category_id'));
        $request->boolean('include_inactive') ? $query->withTrashed() : $query->effectivelyActive();
        $rows = $query->orderBy('sequence')->orderBy('name')->get();

        $types = DB::table('component_subcategory_item_types')->whereIn('component_subcategory_id', $rows->pluck('id'))->get()->groupBy('component_subcategory_id');
        $itemType = $request->input('item_type');

        return $this->ok($rows->map(function (ComponentSubcategory $row) use ($types, $itemType) {
            $allowed = $types->get($row->id)?->pluck('item_type')->sort()->values()->all() ?? [];
            $row->setAttribute('item_types', $allowed);
            if ($itemType) {
                $row->setAttribute('allowed', $allowed === [] || in_array($itemType, $allowed, true));
            }

            return $row;
        })->values());
    }
}
