<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Inventory\Models\WarehouseStock;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\ProductMaster\Models\Product;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class WarehouseStockController extends Controller
{
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
