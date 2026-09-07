<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Inventory\Models\StockOpname;
use App\Domain\Inventory\Models\StockOpnameItem;
use App\Domain\Inventory\Services\StockOpnameService;
use App\Domain\Organization\Models\Warehouse;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class StockOpnameController extends Controller
{
    public function __construct(
        private readonly StockOpnameService $opnames,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $query = StockOpname::query()->where('tenant_id', $tenantId)->with('warehouse');
        $this->scope->applyWarehouseScope($query, $this->context->user(), $tenantId, 'warehouse_id');

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($warehouseId = $request->string('warehouse_id')->value()) {
            $query->where('warehouse_id', $warehouseId);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $request->validate(['warehouse_id' => ['required', 'uuid', 'exists:warehouses,id']]);

        $warehouse = Warehouse::query()->findOrFail($request->input('warehouse_id'));
        abort_unless($warehouse->tenant_id === $tenantId, 404);
        abort_unless($this->scope->canAccessWarehouse($this->context->user(), $tenantId, $warehouse->id), 403);

        $opname = $this->opnames->create($warehouse, $this->context->user()->id);

        return $this->ok($opname, 201);
    }

    public function show(StockOpname $stockOpname)
    {
        $this->authorizeScope($stockOpname);

        return $this->ok($stockOpname->load(['warehouse', 'items.product']));
    }

    public function recordCount(Request $request, StockOpname $stockOpname, StockOpnameItem $item)
    {
        $this->authorizeScope($stockOpname);
        abort_unless($item->stock_opname_id === $stockOpname->id, 404);

        $validated = $request->validate(['physical_quantity' => ['required', 'numeric', 'min:0'], 'notes' => ['nullable', 'string']]);
        $updated = $this->opnames->recordCount($item, (float) $validated['physical_quantity'], $validated['notes'] ?? null);

        return $this->ok($updated);
    }

    public function transitionToCounting(StockOpname $stockOpname)
    {
        return $this->transition($stockOpname, 'COUNTING');
    }

    public function submit(StockOpname $stockOpname)
    {
        return $this->transition($stockOpname, 'SUBMITTED');
    }

    public function approve(StockOpname $stockOpname)
    {
        $this->authorizeScope($stockOpname);

        return $this->ok($this->opnames->approve($stockOpname, $this->context->user()->id));
    }

    public function post(StockOpname $stockOpname)
    {
        $this->authorizeScope($stockOpname);

        return $this->ok($this->opnames->post($stockOpname, $this->context->user()->id));
    }

    private function transition(StockOpname $stockOpname, string $to)
    {
        $this->authorizeScope($stockOpname);

        return $this->ok($this->opnames->transition($stockOpname, $to));
    }

    private function authorizeScope(StockOpname $opname): void
    {
        abort_unless($opname->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessWarehouse($this->context->user(), $this->context->tenantId(), $opname->warehouse_id),
            403,
            'This warehouse is outside your assigned data scope.'
        );
    }
}
