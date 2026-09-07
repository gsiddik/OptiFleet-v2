<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\MasterData\Models\ComponentGroup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreComponentGroupRequest;
use App\Http\Requests\Tenant\UpdateComponentGroupRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class ComponentGroupController extends Controller
{
    public function __construct(private readonly TenantContext $context) {}

    public function index(Request $request)
    {
        $query = ComponentGroup::query();

        if ($search = $request->string('search')->trim()->value()) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'ilike', "%{$search}%")->orWhere('code', 'ilike', "%{$search}%");
            });
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($request->has('parent_id')) {
            $query->where('parent_id', $request->string('parent_id')->value() ?: null);
        }

        return $this->paginated($query->orderBy('sequence')->orderBy('name')->paginate($request->integer('per_page', 50)));
    }

    public function store(StoreComponentGroupRequest $request)
    {
        $group = ComponentGroup::query()->create($request->validated() + [
            'tenant_id' => $this->context->tenantId(),
            'is_system' => false,
            'status' => $request->input('status', 'ACTIVE'),
        ]);

        return $this->ok($group, 201);
    }

    public function show(ComponentGroup $componentGroup)
    {
        $this->authorizeVisible($componentGroup);

        return $this->ok($componentGroup->load(['children', 'vehicleCategories']));
    }

    public function update(UpdateComponentGroupRequest $request, ComponentGroup $componentGroup)
    {
        $this->authorizeVisible($componentGroup);
        abort_if($componentGroup->is_system, 403, 'System master data cannot be modified by a tenant.');

        $componentGroup->update($request->validated());

        return $this->ok($componentGroup);
    }

    public function destroy(ComponentGroup $componentGroup)
    {
        $this->authorizeVisible($componentGroup);
        abort_if($componentGroup->is_system, 403, 'System master data cannot be deleted by a tenant.');

        $componentGroup->update(['status' => 'INACTIVE']);
        $componentGroup->delete();

        return $this->message('Component group deactivated.');
    }

    private function authorizeVisible(ComponentGroup $componentGroup): void
    {
        abort_unless(
            $componentGroup->tenant_id === null || $componentGroup->tenant_id === $this->context->tenantId(),
            404
        );
    }
}
