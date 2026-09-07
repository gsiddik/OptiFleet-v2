<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Inventory\Models\StockTransfer;
use App\Domain\Inventory\Services\StockTransferService;
use App\Domain\Organization\Models\Warehouse;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class StockTransferController extends Controller
{
    public function __construct(
        private readonly StockTransferService $transfers,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $query = StockTransfer::query()->where('tenant_id', $tenantId)->with(['fromWarehouse', 'toWarehouse']);

        $allowedWarehouseIds = $this->scope->allowedWarehouseIds($this->context->user(), $tenantId);
        if ($allowedWarehouseIds !== null) {
            $query->where(fn ($q) => $q->whereIn('from_warehouse_id', $allowedWarehouseIds)->orWhereIn('to_warehouse_id', $allowedWarehouseIds));
        }

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'from_warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'uuid', 'exists:warehouses,id', 'different:from_warehouse_id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
        ]);

        $from = Warehouse::query()->findOrFail($validated['from_warehouse_id']);
        $to = Warehouse::query()->findOrFail($validated['to_warehouse_id']);
        abort_unless($from->tenant_id === $tenantId && $to->tenant_id === $tenantId, 404);
        abort_unless($this->scope->canAccessWarehouse($this->context->user(), $tenantId, $from->id), 403, 'The source warehouse is outside your assigned data scope.');

        $transfer = $this->transfers->create($from, $to, $validated['items'], $this->context->user()->id);

        return $this->ok($transfer, 201);
    }

    public function show(StockTransfer $stockTransfer)
    {
        $this->authorizeScope($stockTransfer);

        return $this->ok($stockTransfer->load(['fromWarehouse', 'toWarehouse', 'items.product']));
    }

    public function submit(StockTransfer $stockTransfer)
    {
        return $this->transition($stockTransfer, 'REQUESTED');
    }

    public function approve(StockTransfer $stockTransfer)
    {
        $this->authorizeScope($stockTransfer);
        $updated = $this->transfers->transition($stockTransfer, 'APPROVED');
        $updated->update(['approved_by' => $this->context->user()->id]);

        return $this->ok($updated->fresh());
    }

    public function prepare(StockTransfer $stockTransfer)
    {
        return $this->transition($stockTransfer, 'PREPARED');
    }

    public function dispatch(StockTransfer $stockTransfer)
    {
        $this->authorizeScope($stockTransfer);

        return $this->ok($this->transfers->dispatch($stockTransfer, $this->context->user()->id));
    }

    public function markInTransit(StockTransfer $stockTransfer)
    {
        return $this->transition($stockTransfer, 'IN_TRANSIT');
    }

    public function receive(Request $request, StockTransfer $stockTransfer)
    {
        $this->authorizeScope($stockTransfer);
        $validated = $request->validate([
            'receipts' => ['required', 'array', 'min:1'],
            'receipts.*.item_id' => ['required', 'uuid'],
            'receipts.*.quantity_received' => ['required', 'numeric', 'min:0'],
            'receipts.*.quantity_damaged' => ['nullable', 'numeric', 'min:0'],
            'receipts.*.quantity_lost' => ['nullable', 'numeric', 'min:0'],
            'receipts.*.discrepancy_reason' => ['nullable', 'string'],
        ]);

        return $this->ok($this->transfers->receive($stockTransfer, $validated['receipts'], $this->context->user()->id));
    }

    public function complete(StockTransfer $stockTransfer)
    {
        return $this->transition($stockTransfer, 'COMPLETED');
    }

    public function reject(StockTransfer $stockTransfer)
    {
        return $this->transition($stockTransfer, 'REJECTED');
    }

    public function cancel(StockTransfer $stockTransfer)
    {
        return $this->transition($stockTransfer, 'CANCELLED');
    }

    private function transition(StockTransfer $stockTransfer, string $to)
    {
        $this->authorizeScope($stockTransfer);

        return $this->ok($this->transfers->transition($stockTransfer, $to));
    }

    private function authorizeScope(StockTransfer $transfer): void
    {
        abort_unless($transfer->tenant_id === $this->context->tenantId(), 404);
        $tenantId = $this->context->tenantId();
        $canFrom = $this->scope->canAccessWarehouse($this->context->user(), $tenantId, $transfer->from_warehouse_id);
        $canTo = $this->scope->canAccessWarehouse($this->context->user(), $tenantId, $transfer->to_warehouse_id);
        abort_unless($canFrom || $canTo, 403, 'This transfer is outside your assigned data scope.');
    }
}
