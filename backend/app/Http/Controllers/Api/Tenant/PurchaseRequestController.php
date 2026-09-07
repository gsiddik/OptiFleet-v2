<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Procurement\Models\PurchaseRequest;
use App\Domain\Procurement\Services\PurchaseRequestService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class PurchaseRequestController extends Controller
{
    public function __construct(
        private readonly PurchaseRequestService $requests,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $query = PurchaseRequest::query()->where('tenant_id', $tenantId)->with(['warehouse', 'items.product']);
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
        $validated = $request->validate([
            'warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'branch_id' => ['nullable', 'uuid', 'exists:branches,id'],
            'workshop_id' => ['nullable', 'uuid', 'exists:workshops,id'],
            'source_type' => ['nullable', 'in:MANUAL,WORK_ORDER,REORDER_POINT,STOCK_PLANNING'],
            'source_reference' => ['nullable', 'string', 'max:255'],
            'required_date' => ['nullable', 'date'],
            'priority' => ['nullable', 'in:LOW,MEDIUM,HIGH,URGENT'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'items.*.requested_quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.estimated_unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.notes' => ['nullable', 'string'],
        ]);

        $warehouse = Warehouse::query()->findOrFail($validated['warehouse_id']);
        abort_unless($warehouse->tenant_id === $tenantId, 404);
        abort_unless($this->scope->canAccessWarehouse($this->context->user(), $tenantId, $warehouse->id), 403);

        $pr = $this->requests->create($warehouse, [
            'branch_id' => $validated['branch_id'] ?? null,
            'workshop_id' => $validated['workshop_id'] ?? null,
            'source_type' => $validated['source_type'] ?? 'MANUAL',
            'source_reference' => $validated['source_reference'] ?? null,
            'required_date' => $validated['required_date'] ?? null,
            'priority' => $validated['priority'] ?? 'MEDIUM',
            'notes' => $validated['notes'] ?? null,
        ], $validated['items'], $this->context->user()->id);

        return $this->ok($pr, 201);
    }

    public function show(PurchaseRequest $purchaseRequest)
    {
        $this->authorizeScope($purchaseRequest);

        return $this->ok($purchaseRequest->load(['warehouse', 'items.product']));
    }

    public function submit(PurchaseRequest $purchaseRequest)
    {
        return $this->transition($purchaseRequest, 'SUBMITTED');
    }

    public function review(PurchaseRequest $purchaseRequest)
    {
        return $this->transition($purchaseRequest, 'UNDER_REVIEW');
    }

    public function approve(PurchaseRequest $purchaseRequest)
    {
        return $this->transition($purchaseRequest, 'APPROVED');
    }

    public function reject(PurchaseRequest $purchaseRequest)
    {
        return $this->transition($purchaseRequest, 'REJECTED');
    }

    public function cancel(PurchaseRequest $purchaseRequest)
    {
        return $this->transition($purchaseRequest, 'CANCELLED');
    }

    private function transition(PurchaseRequest $purchaseRequest, string $to)
    {
        $this->authorizeScope($purchaseRequest);

        return $this->ok($this->requests->transition($purchaseRequest, $to));
    }

    private function authorizeScope(PurchaseRequest $pr): void
    {
        abort_unless($pr->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessWarehouse($this->context->user(), $this->context->tenantId(), $pr->warehouse_id),
            403,
            'This purchase request is outside your assigned data scope.'
        );
    }
}
