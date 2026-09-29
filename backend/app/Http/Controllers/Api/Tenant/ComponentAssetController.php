<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\ComponentAsset\Models\ComponentRepair;
use App\Domain\ComponentAsset\Services\ComponentAssetService;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class ComponentAssetController extends Controller
{
    public function __construct(
        private readonly ComponentAssetService $components,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $user = $this->context->user();
        $query = ComponentAsset::query()->where('tenant_id', $tenantId)->with(['product', 'componentGroup', 'currentVehicle']);

        $allowedBranchIds = $this->scope->allowedBranchIds($user, $tenantId);
        $allowedWarehouseIds = $this->scope->allowedWarehouseIds($user, $tenantId);
        if ($allowedBranchIds !== null || $allowedWarehouseIds !== null) {
            $query->where(function ($q) use ($allowedBranchIds, $allowedWarehouseIds) {
                $q->whereHas('currentVehicle', fn ($vq) => $vq->whereIn('branch_id', $allowedBranchIds ?? []))
                    ->orWhereIn('current_warehouse_id', $allowedWarehouseIds ?? []);
            });
        }

        foreach (['current_status', 'current_vehicle_id', 'component_group_id'] as $filter) {
            if ($value = $request->string($filter)->value()) {
                $query->where($filter, $value);
            }
        }
        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->where('serial_number', 'ilike', "%{$search}%")->orWhere('asset_number', 'ilike', "%{$search}%"));
        }

        return $this->paginated($query->orderByDesc('created_at')->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'product_id' => ['nullable', 'uuid', 'exists:products,id'],
            'component_group_id' => ['nullable', 'uuid', \App\Domain\MasterData\Models\ComponentGroup::selectableRule()],
            'serial_number' => ['nullable', 'string', 'max:100'],
            'asset_number' => ['nullable', 'string', 'max:100'],
            'purchase_date' => ['nullable', 'date'],
            'purchase_cost' => ['nullable', 'numeric', 'min:0'],
            'current_warehouse_id' => ['nullable', 'uuid', 'exists:warehouses,id'],
        ]);

        $asset = ComponentAsset::query()->create($validated + ['tenant_id' => $tenantId, 'current_status' => 'IN_STOCK']);

        return $this->ok($asset, 201);
    }

    public function show(ComponentAsset $componentAsset)
    {
        $this->authorizeScope($componentAsset);

        return $this->ok($componentAsset->load([
            'product', 'componentGroup', 'currentVehicle',
            'installations' => fn ($q) => $q->orderByDesc('installed_at'),
            'installations.vehicle',
            'removals' => fn ($q) => $q->orderByDesc('removed_at'),
            'repairs' => fn ($q) => $q->orderByDesc('started_at'),
        ]));
    }

    public function install(Request $request, ComponentAsset $componentAsset)
    {
        $this->authorizeScope($componentAsset);
        $validated = $request->validate([
            'vehicle_id' => ['required', 'uuid', 'exists:vehicles,id'],
            'position_location' => ['nullable', 'string', 'max:100'],
            'odometer' => ['nullable', 'numeric', 'min:0'],
            'work_order_id' => ['nullable', 'uuid', 'exists:work_orders,id'],
        ]);

        $vehicle = Vehicle::query()->findOrFail($validated['vehicle_id']);
        abort_unless($vehicle->tenant_id === $this->context->tenantId(), 404);

        $installation = $this->components->install($componentAsset, $vehicle, $validated['position_location'] ?? null, $validated['odometer'] ?? null, $validated['work_order_id'] ?? null, $this->context->user()->id);

        return $this->ok($installation, 201);
    }

    public function remove(Request $request, ComponentAsset $componentAsset)
    {
        $this->authorizeScope($componentAsset);
        $validated = $request->validate([
            'removal_reason' => ['required', 'string', 'max:255'],
            'disposition' => ['required', 'in:REUSE,REPAIR,SCRAP'],
            'odometer' => ['nullable', 'numeric', 'min:0'],
            'condition' => ['nullable', 'string', 'max:100'],
            'diagnosis_note' => ['nullable', 'string'],
            'work_order_id' => ['nullable', 'uuid', 'exists:work_orders,id'],
        ]);

        $removal = $this->components->remove($componentAsset, $validated['removal_reason'], $validated['disposition'], $validated['odometer'] ?? null, $validated['condition'] ?? null, $validated['diagnosis_note'] ?? null, $validated['work_order_id'] ?? null, $this->context->user()->id);

        return $this->ok($removal, 201);
    }

    public function replace(Request $request, ComponentAsset $componentAsset)
    {
        $this->authorizeScope($componentAsset);
        $validated = $request->validate([
            'new_component_asset_id' => ['required', 'uuid', 'exists:component_assets,id'],
            'reason' => ['required', 'string', 'max:255'],
            'diagnosis_note' => ['nullable', 'string'],
            'odometer' => ['nullable', 'numeric', 'min:0'],
            'work_order_id' => ['nullable', 'uuid', 'exists:work_orders,id'],
        ]);

        $newAsset = ComponentAsset::query()->findOrFail($validated['new_component_asset_id']);
        abort_unless($newAsset->tenant_id === $this->context->tenantId(), 404);

        return $this->ok($this->components->replace($componentAsset, $newAsset, $validated['reason'], $validated['diagnosis_note'] ?? null, $validated['odometer'] ?? null, $validated['work_order_id'] ?? null, $this->context->user()->id), 201);
    }

    public function startRepair(Request $request, ComponentAsset $componentAsset)
    {
        $this->authorizeScope($componentAsset);
        $validated = $request->validate(['description' => ['required', 'string'], 'work_order_id' => ['nullable', 'uuid', 'exists:work_orders,id']]);

        return $this->ok($this->components->startRepair($componentAsset, $validated['description'], $validated['work_order_id'] ?? null, $this->context->user()->id), 201);
    }

    public function completeRepair(Request $request, ComponentAsset $componentAsset, ComponentRepair $repair)
    {
        $this->authorizeScope($componentAsset);
        abort_unless($repair->component_asset_id === $componentAsset->id, 404);
        $validated = $request->validate(['outcome' => ['required', 'in:RECONDITIONED,SCRAPPED,RETURNED_TO_SERVICE'], 'cost' => ['nullable', 'numeric', 'min:0']]);

        return $this->ok($this->components->completeRepair($repair, $validated['outcome'], $validated['cost'] ?? null));
    }

    private function authorizeScope(ComponentAsset $asset): void
    {
        abort_unless($asset->tenant_id === $this->context->tenantId(), 404);

        $tenantId = $this->context->tenantId();
        $user = $this->context->user();
        $allowedBranchIds = $this->scope->allowedBranchIds($user, $tenantId);
        $allowedWarehouseIds = $this->scope->allowedWarehouseIds($user, $tenantId);
        if ($allowedBranchIds === null && $allowedWarehouseIds === null) {
            return;
        }

        $vehicleBranchId = $asset->current_vehicle_id ? Vehicle::query()->find($asset->current_vehicle_id)?->branch_id : null;
        $inBranch = $vehicleBranchId && in_array($vehicleBranchId, $allowedBranchIds, true);
        $inWarehouse = $asset->current_warehouse_id && in_array($asset->current_warehouse_id, $allowedWarehouseIds, true);

        abort_unless($inBranch || $inWarehouse, 403, 'This component asset is outside your assigned data scope.');
    }
}
