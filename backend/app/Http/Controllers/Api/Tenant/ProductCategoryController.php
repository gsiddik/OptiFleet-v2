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

        return $this->paginated($query->orderBy('name')->paginate($request->integer('per_page', 50)));
    }

    public function store(StoreProductCategoryRequest $request)
    {
        $category = ProductCategory::query()->create($request->validated() + [
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
            'description' => ['nullable', 'string'],
            'status' => ['sometimes', 'in:ACTIVE,INACTIVE'],
        ]);
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
