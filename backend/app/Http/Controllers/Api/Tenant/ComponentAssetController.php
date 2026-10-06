<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\ComponentAsset\Models\ComponentAsset;
use App\Domain\ComponentAsset\Models\ComponentRepair;
use App\Domain\ComponentAsset\Services\ComponentAssetRegisterService;
use App\Domain\ComponentAsset\Services\ComponentAssetService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Vehicle\Models\Vehicle;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ComponentAssetController extends Controller
{
    public function __construct(
        private readonly ComponentAssetService $components,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
        private readonly ComponentAssetRegisterService $register,
    ) {}

    /**
     * Inventory → Component Assets: the physical-asset register (generated component assets and
     * the existing physical tires, whose Serial Number is the Asset#), with the location derived
     * from the current relations. Tenant and data scope apply.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'max:30'],
            'kind' => ['nullable', 'in:COMPONENT,TIRE'],
            'component_group_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $validated['status'] ??= $request->string('current_status')->value() ?: null; // legacy filter name

        return $this->paginated($this->register->paginate($this->context->tenantId(), $this->context->user(), $validated, (int) ($validated['per_page'] ?? 20)));
    }

    /**
     * Deprecated: physical assets come from Goods Receipt (and tires from tire registration), not
     * from manual creation — the New Component Asset button was removed. Kept as a route so old
     * clients get a clear answer; ComponentAsset::create stays available to services and seeders.
     */
    public function store()
    {
        return response()->json(['message' => 'Component Assets are generated from Goods Receipt (one per unit received of a serial-tracked product); manual creation is no longer available.'], 410);
    }

    public function show(ComponentAsset $componentAsset)
    {
        $this->authorizeScope($componentAsset);

        $asset = $componentAsset->load([
            'product.componentGroups', 'componentGroup', 'currentVehicle', 'currentWarehouse',
            'installations' => fn ($q) => $q->orderByDesc('installed_at'),
            'installations.vehicle',
            'removals' => fn ($q) => $q->orderByDesc('removed_at'),
            'repairs' => fn ($q) => $q->orderByDesc('started_at'),
        ]);
        $source = $asset->goods_receipt_item_id ? DB::table('goods_receipt_items as gi')->join('goods_receipts as gr', 'gr.id', '=', 'gi.goods_receipt_id')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'gr.purchase_order_id')->where('gi.id', $asset->goods_receipt_item_id)
            ->first(['gr.id as goods_receipt_id', 'gr.gr_number', 'po.id as purchase_order_id', 'po.po_number']) : null;

        return $this->ok($asset->toArray() + [
            'location' => $this->locationOf($asset),
            'source' => $source,
            'sale' => $asset->spare_part_sale_id ? DB::table('spare_part_sales')->where('id', $asset->spare_part_sale_id)->first(['id', 'status', 'buyer_name', 'unit_price', 'decided_at']) : null,
            'purchase_return' => $asset->purchase_return_id ? DB::table('purchase_returns')->where('id', $asset->purchase_return_id)->first(['id', 'return_number', 'status']) : null,
            // Status history: the existing audit trail of this asset's status / location changes.
            'status_history' => self::chronological(DB::table('audit_logs')->where('tenant_id', $asset->tenant_id)->where('resource_type', 'ComponentAsset')->where('resource_id', $asset->id)
                ->orderBy('created_at')->get(['action', 'old_values', 'new_values', 'created_at'])
                ->map(fn ($log) => ['action' => $log->action, 'at' => $log->created_at,
                    'from' => json_decode((string) $log->old_values, true)['current_status'] ?? null,
                    'to' => json_decode((string) $log->new_values, true)['current_status'] ?? null])
                ->filter(fn ($h) => $h['action'] === 'created' || $h['to'] !== null)->values()->all()),
        ]);
    }

    /**
     * audit_logs.created_at has second precision, so changes made within the same second tie. Within a tie the
     * entries are put in transition order: each one starts from the status the previous one ended in.
     */
    private static function chronological(array $history): array
    {
        $ordered = [];
        $previousTo = null;
        foreach (collect($history)->groupBy('at', preserveKeys: false) as $group) {
            $remaining = $group->values()->all();
            while ($remaining !== []) {
                $created = array_search('created', array_column($remaining, 'action'), true);
                $index = $created === false ? 0 : $created;
                foreach ($remaining as $i => $entry) {
                    if ($entry['from'] !== null && $entry['from'] === $previousTo) {
                        $index = $i;
                        break;
                    }
                }
                $entry = $remaining[$index];
                array_splice($remaining, $index, 1);
                $ordered[] = $entry;
                $previousTo = $entry['to'] ?? $previousTo;
            }
        }

        return $ordered;
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
            'warehouse_id' => ['nullable', 'uuid'],
        ]);
        $warehouseId = $this->warehouseInScope($validated['warehouse_id'] ?? null);

        $removal = $this->components->remove($componentAsset, $validated['removal_reason'], $validated['disposition'], $validated['odometer'] ?? null, $validated['condition'] ?? null, $validated['diagnosis_note'] ?? null, $validated['work_order_id'] ?? null, $this->context->user()->id, $warehouseId);

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
        $validated = $request->validate(['outcome' => ['required', 'in:RECONDITIONED,SCRAPPED,RETURNED_TO_SERVICE'], 'cost' => ['nullable', 'numeric', 'min:0'], 'warehouse_id' => ['nullable', 'uuid']]);

        return $this->ok($this->components->completeRepair($repair, $validated['outcome'], $validated['cost'] ?? null, $this->warehouseInScope($validated['warehouse_id'] ?? null)));
    }

    /** Location from the current relations (same rule as the register list). */
    private function locationOf(ComponentAsset $asset): ?array
    {
        if (in_array($asset->current_status, ComponentAsset::NO_LOCATION, true)) {
            return null;
        }
        $active = $asset->installations->firstWhere('removed_at', null);
        if ($active) {
            return ['type' => 'VEHICLE', 'id' => $active->vehicle_id, 'label' => $active->vehicle?->registration_number];
        }

        return $asset->currentWarehouse ? ['type' => 'WAREHOUSE', 'id' => $asset->current_warehouse_id, 'label' => $asset->currentWarehouse->name] : null;
    }

    /** A warehouse of this tenant inside the user's data scope (or null when none is given). */
    private function warehouseInScope(?string $warehouseId): ?string
    {
        if ($warehouseId === null) {
            return null;
        }
        $tenantId = $this->context->tenantId();
        abort_unless(Warehouse::query()->where('tenant_id', $tenantId)->whereKey($warehouseId)->exists(), 422, 'Unknown warehouse.');
        abort_unless($this->scope->canAccessWarehouse($this->context->user(), $tenantId, $warehouseId), 403, 'This warehouse is outside your data scope.');

        return $warehouseId;
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
        $inBranch = $vehicleBranchId && in_array($vehicleBranchId, $allowedBranchIds ?? [], true);
        $inWarehouse = $asset->current_warehouse_id && in_array($asset->current_warehouse_id, $allowedWarehouseIds ?? [], true);

        abort_unless($inBranch || $inWarehouse, 403, 'This component asset is outside your assigned data scope.');
    }
}
