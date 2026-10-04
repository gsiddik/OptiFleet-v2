<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Inventory\Services\UsedSparepartAvailabilityService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Domain\Tire\Models\UsedTireStock;
use App\Domain\Tire\Services\UsedTireStockService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class WarehouseStockController extends Controller
{
    /** Warehouse Stock tabs, classified by the canonical Item Type (products.product_type). */
    public const ITEM_GROUPS = [
        'PARTS_SUPPLIES' => ['SPARE_PART', 'CONSUMABLE', 'RIM', 'TIRE'],
        'TOOLS_EQUIPMENT' => ['TOOL', 'EQUIPMENT'],
    ];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $query = WarehouseStock::query()->where('tenant_id', $tenantId)->with(['warehouse', 'product.category', 'product.uom']);
        $this->scope->applyWarehouseScope($query, $this->context->user(), $tenantId, 'warehouse_id');

        if ($warehouseId = $request->string('warehouse_id')->value()) {
            $query->where('warehouse_id', $warehouseId);
        }
        if ($productId = $request->string('product_id')->value()) {
            $query->where('product_id', $productId);
        }
        if ($search = $request->string('search')->trim()->value()) {
            $query->whereHas('product', fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%"));
        }
        $request->validate(['item_group' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::ITEM_GROUPS))]]);
        if ($group = $request->string('item_group')->value()) {
            $query->whereHas('product', fn ($q) => $q->withTrashed()->whereIn('product_type', self::ITEM_GROUPS[$group]));
        }

        $paginator = $query->paginate($request->integer('per_page', 20));

        $status = $request->string('reorder_status')->value();
        if ($status) {
            $filtered = $paginator->getCollection()->filter(fn (WarehouseStock $s) => $s->reorderStatus() === $status)->values();

            return response()->json(['data' => $filtered, 'meta' => [
                'current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(), 'total' => $filtered->count(),
            ]]);
        }

        return $this->paginated($paginator, fn (WarehouseStock $s) => array_merge($s->toArray(), [
            'quantity_available' => $s->quantityAvailable(),
            'reorder_status' => $s->reorderStatus(),
            'warehouse' => $s->warehouse,
            'product' => $s->product,
        ]));
    }

    /**
     * Used Spareparts tab: used parts by availability — REUSABLE (already counted once in the
     * product's on-hand), QUARANTINE and REPAIR_PENDING (physical, never available). Read-only,
     * warehouse data scope applied; summary totals are kept per category, never added together.
     */
    public function usedSpareparts(Request $request, UsedSparepartAvailabilityService $usedParts)
    {
        $tenantId = $this->context->tenantId();
        $request->validate([
            'category' => ['nullable', 'string', 'in:'.implode(',', UsedSparepartAvailabilityService::CATEGORIES)],
            'warehouse_id' => ['nullable', 'uuid'], 'product_id' => ['nullable', 'uuid'],
            'work_order_id' => ['nullable', 'uuid'], 'vehicle_id' => ['nullable', 'uuid'],
        ]);
        $query = $usedParts->query()->where('tenant_id', $tenantId);
        $this->scope->applyWarehouseScope($query, $this->context->user(), $tenantId, 'warehouse_id');

        foreach (['warehouse_id', 'product_id', 'work_order_id'] as $filter) {
            if ($value = $request->string($filter)->value()) {
                $query->where($filter, $value);
            }
        }
        if ($vehicleId = $request->string('vehicle_id')->value()) {
            $query->whereHas('workOrder', fn ($w) => $w->where('vehicle_id', $vehicleId));
        }
        if ($search = $request->string('search')->trim()->value()) {
            $query->where(fn ($q) => $q->whereHas('product', fn ($p) => $p->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%"))
                ->orWhereHas('workOrder', fn ($w) => $w->where('wo_number', 'ilike', "%{$search}%")
                    ->orWhereHas('vehicle', fn ($v) => $v->where('registration_number', 'ilike', "%{$search}%"))));
        }
        $summary = $usedParts->summary($query);
        if ($category = $request->string('category')->value()) {
            $usedParts->applyCategory($query, $category);
        }

        $paginator = $query->with([
            'product:id,name,sku,default_storage_bin_id', 'product.defaultStorageBin:id,code,name', 'warehouse:id,code,name',
            'workOrder:id,wo_number,vehicle_id', 'workOrder.vehicle:id,registration_number',
        ])->latest('finalized_at')->paginate($request->integer('per_page', 20));

        $response = $this->paginated($paginator, fn ($r) => [
            'id' => $r->id,
            'category' => UsedSparepartAvailabilityService::categoryOf($r),
            'available_for_issue' => false, // reusable quantity is issued from the product's regular stock
            'quantity' => (string) UsedSparepartAvailabilityService::quantityOf($r),
            'condition' => $r->condition,
            'disposition' => $r->disposition,
            'disposition_status' => $r->disposition_status,
            'repaired' => $r->repair_completed_at !== null,
            'finalized_at' => optional($r->finalized_at)->toIso8601String(),
            'product' => $r->product?->only(['id', 'name', 'sku']),
            'storage_bin' => $r->product?->defaultStorageBin?->only(['id', 'code', 'name']),
            'warehouse' => $r->warehouse?->only(['id', 'code', 'name']),
            'work_order' => $r->workOrder ? ['id' => $r->workOrder->id, 'wo_number' => $r->workOrder->wo_number] : null,
            'vehicle' => $r->workOrder?->vehicle?->only(['id', 'registration_number']),
        ]);
        $payload = $response->getData(true);
        $payload['meta']['summary'] = $summary;

        return response()->json($payload);
    }

    /**
     * Warehouse Stock → Used Tires: the used tire quantity (REUSE tires received from Used Tire
     * Management) per warehouse and tire product, with the serials counted there. Issued through
     * Part Requests (USED lines); never part of the new-stock On Hand.
     */
    public function usedTires(Request $request, UsedTireStockService $usedStock)
    {
        $tenantId = $this->context->tenantId();
        $request->validate(['warehouse_id' => ['nullable', 'uuid'], 'product_id' => ['nullable', 'uuid']]);
        $query = UsedTireStock::query()->where('tenant_id', $tenantId)->with(['warehouse:id,code,name', 'product:id,name,sku']);
        $this->scope->applyWarehouseScope($query, $this->context->user(), $tenantId, 'warehouse_id');
        foreach (['warehouse_id', 'product_id'] as $filter) {
            if ($value = $request->string($filter)->value()) {
                $query->where($filter, $value);
            }
        }
        if ($search = $request->string('search')->trim()->value()) {
            $query->whereHas('product', fn ($q) => $q->where('name', 'ilike', "%{$search}%")->orWhere('sku', 'ilike', "%{$search}%"));
        }
        if (! $request->boolean('include_empty')) {
            $query->where('quantity_on_hand', '>', 0);
        }

        $paginator = $query->orderByDesc('updated_at')->paginate($request->integer('per_page', 20));

        return $this->paginated($paginator, fn (UsedTireStock $s) => [
            'id' => $s->id,
            'warehouse' => $s->warehouse?->only(['id', 'code', 'name']),
            'product' => $s->product?->only(['id', 'name', 'sku']),
            'quantity_on_hand' => $s->quantity_on_hand,
            'serials' => $usedStock->countedSerials($s->warehouse_id, $s->product_id),
        ]);
    }

    public function show(WarehouseStock $warehouseStock)
    {
        $this->authorizeScope($warehouseStock);

        return $this->ok(array_merge(
            $warehouseStock->load(['warehouse', 'product.category', 'product.uom'])->toArray(),
            ['quantity_available' => $warehouseStock->quantityAvailable(), 'reorder_status' => $warehouseStock->reorderStatus()]
        ));
    }

    public function updateThresholds(Request $request, WarehouseStock $warehouseStock)
    {
        $this->authorizeScope($warehouseStock);

        $validated = $request->validate([
            'minimum_stock' => ['nullable', 'numeric', 'min:0'],
            'maximum_stock' => ['nullable', 'numeric', 'min:0'],
            'reorder_point' => ['nullable', 'numeric', 'min:0'],
        ]);
        $warehouseStock->update($validated);

        return $this->ok($warehouseStock->fresh());
    }

    public function adjust(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'direction' => ['required', 'in:PLUS,MINUS'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $warehouse = Warehouse::query()->findOrFail($validated['warehouse_id']);
        abort_unless($warehouse->tenant_id === $tenantId, 404);
        abort_unless($this->scope->canAccessWarehouse($this->context->user(), $tenantId, $warehouse->id), 403, 'This warehouse is outside your assigned data scope.');
        $product = Product::query()->findOrFail($validated['product_id']);

        $stock = $this->inventory->adjust($warehouse, $product, (float) $validated['quantity'], $validated['direction'], $this->context->user()->id, $validated['reason']);

        return $this->ok($stock, 201);
    }

    /**
     * G-20: InventoryService::scrap() has existed since Phase 4 (guarded,
     * ledger-writing) with no route, permission, or UI ever calling it —
     * this is that missing entry point for scrapping available on-hand
     * stock directly (distinct from Phase B's used-part disposition SCRAP,
     * which disposes of quantity that was never on-hand).
     */
    public function scrap(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'product_id' => ['required', 'uuid', 'exists:products,id'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $warehouse = Warehouse::query()->findOrFail($validated['warehouse_id']);
        abort_unless($warehouse->tenant_id === $tenantId, 404);
        abort_unless($this->scope->canAccessWarehouse($this->context->user(), $tenantId, $warehouse->id), 403, 'This warehouse is outside your assigned data scope.');
        $product = Product::query()->findOrFail($validated['product_id']);

        $stock = $this->inventory->scrap($warehouse, $product, (float) $validated['quantity'], $this->context->user()->id, $validated['reason']);

        return $this->ok($stock, 201);
    }

    private function authorizeScope(WarehouseStock $stock): void
    {
        abort_unless($stock->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessWarehouse($this->context->user(), $this->context->tenantId(), $stock->warehouse_id),
            403,
            'This warehouse is outside your assigned data scope.'
        );
    }
}
