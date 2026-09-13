<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Workshop\Models\Workspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\StoreWorkspaceRequest;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function __construct(
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $user = $this->context->user();
        $tenantId = $this->context->tenantId();

        $query = Workspace::query()->with(['workshop', 'vehicleCategories']);
        $allowedWorkshopIds = $this->scope->allowedWorkshopIds($user, $tenantId);
        if ($allowedWorkshopIds !== null) {
            $query->whereIn('workshop_id', $allowedWorkshopIds);
        }

        foreach (['status', 'workspace_type', 'workshop_id'] as $filter) {
            if ($value = $request->string($filter)->value()) {
                $query->where($filter, $value);
            }
        }

        return $this->paginated($query->paginate($request->integer('per_page', 20)));
    }

    public function store(StoreWorkspaceRequest $request)
    {
        abort_unless($this->scope->canAccessWorkshop($this->context->user(), $this->context->tenantId(), $request->input('workshop_id')), 403);

        $workspace = Workspace::query()->create($request->validated() + ['status' => 'AVAILABLE']);

        return $this->ok($workspace, 201);
    }

    public function show(Workspace $workspace)
    {
        $this->authorizeScope($workspace);

        return $this->ok($workspace->load(['workshop', 'vehicleCategories']));
    }

    public function update(Request $request, Workspace $workspace)
    {
        $this->authorizeScope($workspace);
        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'workspace_type' => ['sometimes', 'in:GENERAL_SERVICE_BAY,HEAVY_VEHICLE_BAY,INSPECTION_BAY,ELECTRICAL_BAY,TIRE_BAY,QC_BAY,WASHING_BAY,PARKING_LOT,HOLDING_AREA,OTHER'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'capacity_unit' => ['nullable', 'string', 'max:50'],
        ]);
        $workspace->update($validated);

        return $this->ok($workspace->fresh());
    }

    public function block(Workspace $workspace)
    {
        $this->authorizeScope($workspace);
        $workspace->update(['status' => 'BLOCKED']);

        return $this->ok($workspace->fresh());
    }

    public function unblock(Workspace $workspace)
    {
        $this->authorizeScope($workspace);
        $workspace->update(['status' => 'AVAILABLE']);

        return $this->ok($workspace->fresh());
    }

    public function syncVehicleCategories(Request $request, Workspace $workspace)
    {
        $this->authorizeScope($workspace);
        $request->validate(['vehicle_category_ids' => ['array'], 'vehicle_category_ids.*' => ['uuid']]);

        $workspace->vehicleCategories()->sync($request->input('vehicle_category_ids', []));

        return $this->ok($workspace->fresh('vehicleCategories'));
    }

    private function authorizeScope(Workspace $workspace): void
    {
        abort_unless($workspace->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessWorkshop($this->context->user(), $this->context->tenantId(), $workspace->workshop_id),
            403,
            'This workspace is outside your assigned data scope.'
        );
    }
}
