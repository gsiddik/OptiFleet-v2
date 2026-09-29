<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\MasterData\Models\ComponentCategory;
use App\Domain\MasterData\Services\ComponentClassificationService;
use App\Domain\MasterData\Support\ComponentClassificationListing;
use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreComponentCategoryRequest;
use App\Http\Requests\MasterData\UpdateComponentCategoryRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Tenant portal: platform baseline Categories are visible read-only; the
 * tenant fully manages the Categories it created (under any Component Group
 * it can see). Deletion is always a soft delete.
 */
class ComponentCategoryController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ComponentClassificationService $classification,
    ) {}

    public function index(Request $request)
    {
        $query = ComponentClassificationListing::categories(ComponentCategory::query(), $request);

        return $this->paginated($query->paginate($request->integer('per_page', 50)), ComponentClassificationListing::presentCategory(...));
    }

    public function store(StoreComponentCategoryRequest $request)
    {
        $category = $this->classification->createCategory($request->validated(), $this->context->tenantId());

        return $this->ok(ComponentClassificationListing::presentCategory($category->refresh()->load('componentGroup')), 201);
    }

    public function show(ComponentCategory $componentCategory)
    {
        return $this->ok(ComponentClassificationListing::presentCategory($componentCategory->load('componentGroup')));
    }

    public function update(UpdateComponentCategoryRequest $request, ComponentCategory $componentCategory)
    {
        $this->authorizeOwned($componentCategory);
        $category = $this->classification->updateCategory($componentCategory, $request->validated());

        return $this->ok(ComponentClassificationListing::presentCategory($category->load('componentGroup')));
    }

    public function destroy(ComponentCategory $componentCategory)
    {
        $this->authorizeOwned($componentCategory);
        $this->classification->delete($componentCategory);

        return $this->message('Category deleted. It is no longer available for new Products; existing records keep their reference.');
    }

    public function restore(ComponentCategory $componentCategory)
    {
        $this->authorizeOwned($componentCategory);

        return $this->ok(ComponentClassificationListing::presentCategory($this->classification->restore($componentCategory)->load('componentGroup')));
    }

    private function authorizeOwned(ComponentCategory $category): void
    {
        abort_if($category->tenant_id === null, 403, 'System master data cannot be modified by a tenant.');
        abort_unless($category->tenant_id === $this->context->tenantId(), 404);
    }
}
