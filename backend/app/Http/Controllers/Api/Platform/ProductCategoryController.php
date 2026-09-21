<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\ProductCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreProductCategoryRequest;
use App\Http\Requests\Platform\UpdateProductCategoryRequest;
use Illuminate\Http\Request;

/**
 * "Next Improvement Tenant Portal - Products": "Fitur Product Categories
 * hanya dikelola oleh Superadmin" — this replaces the tenant-side create/
 * update/delete for Product Categories entirely. Tenants keep read-only
 * access via the existing GET /app/product-categories for building the
 * Dynamic Product Form; only platform staff can manage the catalog here.
 */
class ProductCategoryController extends Controller
{
    public function index(Request $request)
    {
        $query = ProductCategory::query()->whereNull('tenant_id');

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%"));
        }
        if ($itemType = $request->string('item_type')->value()) {
            $query->where('item_type', $itemType);
        }
        if ($request->has('parent_id')) {
            $query->where('parent_id', $request->string('parent_id')->value() ?: null);
        }

        return $this->paginated($query->orderBy('name')->paginate($request->integer('per_page', 50)));
    }

    public function store(StoreProductCategoryRequest $request)
    {
        $validated = $request->validated();

        if (! empty($validated['parent_id'])) {
            $parent = ProductCategory::query()->findOrFail($validated['parent_id']);
            $validated['item_type'] = $parent->item_type;
        }

        $category = ProductCategory::query()->create($validated + ['tenant_id' => null, 'is_system' => true, 'status' => 'ACTIVE']);

        return $this->ok($category, 201);
    }

    public function update(UpdateProductCategoryRequest $request, ProductCategory $productCategory)
    {
        $validated = $request->validated();
        if ($productCategory->parent_id !== null) {
            unset($validated['item_type']);
        }
        $productCategory->update($validated);

        return $this->ok($productCategory->fresh());
    }

    public function destroy(ProductCategory $productCategory)
    {
        abort_if(Product::query()->where('product_category_id', $productCategory->id)->exists(), 422, 'This product category is used by one or more products and cannot be deleted.');
        abort_if(ProductCategory::query()->where('parent_id', $productCategory->id)->exists(), 422, 'This category has subcategories and cannot be deleted.');

        $productCategory->delete();

        return $this->ok(['deleted' => true]);
    }
}
