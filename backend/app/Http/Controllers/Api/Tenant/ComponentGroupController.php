<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\MasterData\Models\ComponentGroup;
use App\Domain\MasterData\Services\ComponentGroupService;
use App\Domain\MasterData\Support\ComponentGroupListing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreComponentGroupRequest;
use App\Http\Requests\Tenant\UpdateComponentGroupRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * Tenant portal: a tenant sees platform baseline groups (read-only here —
 * they are managed by the platform) plus its own groups, which it fully
 * manages. Deletion is always a soft delete.
 */
class ComponentGroupController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly ComponentGroupService $groups,
    ) {}

    public function index(Request $request)
    {
        $query = ComponentGroupListing::apply(ComponentGroup::query(), $request);

        return $this->paginated($query->paginate($request->integer('per_page', 50)), ComponentGroupListing::present(...));
    }

    public function store(StoreComponentGroupRequest $request)
    {
        $validated = $request->validated();
        $group = $this->groups->create($validated + ['status' => $validated['status'] ?? 'ACTIVE'], $this->context->tenantId());

        return $this->ok(ComponentGroupListing::present($group->refresh()), 201);
    }

    public function show(ComponentGroup $componentGroup)
    {
        $this->authorizeVisible($componentGroup);

        return $this->ok(ComponentGroupListing::present($componentGroup->load(['children', 'vehicleCategories'])));
    }

    public function update(UpdateComponentGroupRequest $request, ComponentGroup $componentGroup)
    {
        $this->authorizeVisible($componentGroup);
        abort_if($componentGroup->is_system, 403, 'System master data cannot be modified by a tenant.');

        return $this->ok(ComponentGroupListing::present($this->groups->update($componentGroup, $request->validated())));
    }

    public function destroy(ComponentGroup $componentGroup)
    {
        $this->authorizeVisible($componentGroup);
        abort_if($componentGroup->is_system, 403, 'System master data cannot be deleted by a tenant.');

        $this->groups->delete($componentGroup);

        return $this->message('Component group deleted. Existing Products and historical records keep their reference.');
    }

    public function restore(ComponentGroup $componentGroup)
    {
        $this->authorizeVisible($componentGroup);
        abort_if($componentGroup->is_system, 403, 'System master data cannot be restored by a tenant.');

        return $this->ok(ComponentGroupListing::present($this->groups->restore($componentGroup)));
    }

    private function authorizeVisible(ComponentGroup $componentGroup): void
    {
        abort_unless(
            $componentGroup->tenant_id === null || $componentGroup->tenant_id === $this->context->tenantId(),
            404
        );
    }
}
