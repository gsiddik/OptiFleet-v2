<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\ProductMaster\Models\Product;
use App\Domain\ProductMaster\Models\ProductCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreProductCategoryRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class ProductCategoryController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

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

    public function store(StoreProductCategoryRequest $request)
    {
        $validated = $request->validated();

        if (! empty($validated['parent_id'])) {
            $parent = ProductCategory::query()->findOrFail($validated['parent_id']);
            $this->authorizeVisible($parent);
            $validated['item_type'] = $parent->item_type;
        }

        $category = ProductCategory::query()->create($validated + [
            'tenant_id' => $this->context->tenantId(), 'is_system' => false, 'status' => 'ACTIVE',
        ]);

        return $this->ok($category, 201);
    }

    public function update(Request $request, ProductCategory $productCategory)
    {
        $this->authorizeVisible($productCategory);
        abort_if($productCategory->is_system, 403, 'System master data cannot be modified by a tenant.');

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'item_type' => ['sometimes', 'nullable', 'in:SPARE_PART,TOOL,TIRE,CONSUMABLE,EQUIPMENT,RIM,OTHER'],
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
        // A subcategory's item_type always mirrors its parent's — never
        // independently editable, so it can't drift out of sync.
        if ($productCategory->parent_id !== null) {
            unset($validated['item_type']);
        }
        $productCategory->update($validated);

        return $this->ok($productCategory->fresh());
    }

    public function destroy(ProductCategory $productCategory)
    {
        $this->authorizeVisible($productCategory);
        abort_if($productCategory->is_system, 403, 'System master data cannot be modified by a tenant.');
        abort_if(Product::query()->where('product_category_id', $productCategory->id)->exists(), 422, 'This product category is used by one or more products and cannot be deleted.');

        $productCategory->delete();

        return $this->ok(['deleted' => true]);
    }

    private function authorizeVisible(ProductCategory $category): void
    {
        abort_unless($category->tenant_id === null || $category->tenant_id === $this->context->tenantId(), 404);
    }
}
