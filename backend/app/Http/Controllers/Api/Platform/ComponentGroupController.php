<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\MasterData\Services\ComponentGroupService;
use App\Domain\MasterData\Support\ComponentGroupListing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Platform\StoreComponentGroupRequest;
use App\Http\Requests\Platform\UpdateComponentGroupRequest;
use Illuminate\Http\Request;

/**
 * Platform portal management of the shared (tenant_id NULL) Component Group
 * baseline that every tenant sees. Once a platform admin edits a baseline
 * row, ComponentGroupSeeder never overwrites it again; soft-deleted rows are
 * never resurrected by the seeder either — only by an explicit restore here.
 */
class ComponentGroupController extends Controller
{
    public function __construct(private readonly ComponentGroupService $groups) {}

    public function index(Request $request)
    {
        $query = ComponentGroupListing::apply(ComponentGroup::query()->whereNull('tenant_id'), $request);

        return $this->paginated($query->paginate($request->integer('per_page', 50)), ComponentGroupListing::present(...));
    }

    public function store(StoreComponentGroupRequest $request)
    {
        $validated = $request->validated();
        $group = $this->groups->create($validated + ['status' => $validated['status'] ?? 'ACTIVE'], null);

        return $this->ok(ComponentGroupListing::present($group->refresh()), 201);
    }

    public function update(UpdateComponentGroupRequest $request, ComponentGroup $componentGroup)
    {
        abort_unless($componentGroup->tenant_id === null, 404);

        return $this->ok(ComponentGroupListing::present($this->groups->update($componentGroup, $request->validated())));
    }

    public function destroy(ComponentGroup $componentGroup)
    {
        abort_unless($componentGroup->tenant_id === null, 404);

        $this->groups->delete($componentGroup);

        return $this->message('Component group deleted. Existing Products and historical records keep their reference.');
    }

    public function restore(ComponentGroup $componentGroup)
    {
        abort_unless($componentGroup->tenant_id === null, 404);

        return $this->ok(ComponentGroupListing::present($this->groups->restore($componentGroup)));
    }
}
