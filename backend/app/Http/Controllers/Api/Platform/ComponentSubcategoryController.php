<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\MasterData\Models\ComponentSubcategory;
use App\Domain\MasterData\Services\ComponentClassificationService;
use App\Domain\MasterData\Support\ComponentClassificationListing;
use App\Http\Controllers\Controller;
use App\Http\Requests\MasterData\StoreComponentSubcategoryRequest;
use App\Http\Requests\MasterData\UpdateComponentSubcategoryRequest;
use Illuminate\Http\Request;

/**
 * Platform portal: management of the shared baseline Subcategory rows (tenant_id
 * NULL) every tenant sees. Seeder never overwrites or resurrects rows edited
 * or deleted here. Soft delete only.
 */
class ComponentSubcategoryController extends Controller
{
    public function __construct(private readonly ComponentClassificationService $classification) {}

    public function index(Request $request)
    {
        $query = ComponentClassificationListing::subcategories(ComponentSubcategory::query()->whereNull('component_subcategories.tenant_id'), $request);

        return $this->paginated(ComponentClassificationListing::presentSubcategories($query->paginate($request->integer('per_page', 50))));
    }

    public function store(StoreComponentSubcategoryRequest $request)
    {
        $subcategory = $this->classification->createSubcategory($request->validated(), null);

        return $this->ok($this->present($subcategory->refresh()), 201);
    }

    public function show(ComponentSubcategory $componentSubcategory)
    {
        $this->authorizeOwned($componentSubcategory);
        return $this->ok($this->present($componentSubcategory));
    }

    public function update(UpdateComponentSubcategoryRequest $request, ComponentSubcategory $componentSubcategory)
    {
        $this->authorizeOwned($componentSubcategory);

        return $this->ok($this->present($this->classification->updateSubcategory($componentSubcategory, $request->validated())));
    }

    public function destroy(ComponentSubcategory $componentSubcategory)
    {
        $this->authorizeOwned($componentSubcategory);
        $this->classification->delete($componentSubcategory);

        return $this->message('Subcategory deleted. It is no longer available for new Products; existing records keep their reference.');
    }

    public function restore(ComponentSubcategory $componentSubcategory)
    {
        $this->authorizeOwned($componentSubcategory);

        return $this->ok($this->present($this->classification->restore($componentSubcategory)));
    }

    private function present(ComponentSubcategory $subcategory): ComponentSubcategory
    {
        return ComponentClassificationListing::presentSubcategory($subcategory->load('category.componentGroup'));
    }

    private function authorizeOwned(ComponentSubcategory $subcategory): void
    {
        abort_unless($subcategory->tenant_id === null, 404);
    }
}
