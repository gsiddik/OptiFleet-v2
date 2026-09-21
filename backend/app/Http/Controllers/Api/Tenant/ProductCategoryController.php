<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\ProductMaster\Models\ProductCategory;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * "Next Improvement Tenant Portal - Products": "Fitur Product Categories
 * hanya dikelola oleh Superadmin" — this tenant-portal controller is now
 * read-only. Create/update/delete moved to
 * App\Http\Controllers\Api\Platform\ProductCategoryController.
 */
class ProductCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = ProductCategory::query();

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"));
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($itemType = $request->string('item_type')->value()) {
            // A category dropdown scoped to an Item Type shows both categories
            // tagged for it AND unscoped ones (item_type = NULL), so every
            // pre-Phase-2 category keeps appearing everywhere it always has.
            $query->where(fn ($q) => $q->where('item_type', $itemType)->orWhereNull('item_type'));
        }
        if ($request->has('parent_id')) {
            $query->where('parent_id', $request->string('parent_id')->value() ?: null);
        }

        return $this->paginated($query->orderBy('name')->paginate($request->integer('per_page', 50)));
    }
}
