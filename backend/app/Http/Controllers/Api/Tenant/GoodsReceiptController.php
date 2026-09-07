<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Services\GoodsReceiptService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class GoodsReceiptController extends Controller
{
    public function __construct(
        private readonly GoodsReceiptService $receipts,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $query = GoodsReceipt::query()->where('tenant_id', $tenantId)->with(['warehouse', 'partner', 'purchaseOrder']);
        $this->scope->applyWarehouseScope($query, $this->context->user(), $tenantId, 'warehouse_id');

        if ($poId = $request->string('purchase_order_id')->value()) {
            $query->where('purchase_order_id', $poId);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request, PurchaseOrder $purchaseOrder)
    {
        $tenantId = $this->context->tenantId();
        abort_unless($purchaseOrder->tenant_id === $tenantId, 404);
        abort_unless($this->scope->canAccessWarehouse($this->context->user(), $tenantId, $purchaseOrder->delivery_warehouse_id), 403);

        $validated = $request->validate([
            'warehouse_id' => ['nullable', 'uuid', 'exists:warehouses,id'],
            'notes' => ['nullable', 'string'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.purchase_order_item_id' => ['required', 'uuid', 'exists:purchase_order_items,id'],
            'lines.*.quantity_accepted' => ['required', 'numeric', 'min:0'],
            'lines.*.quantity_rejected' => ['nullable', 'numeric', 'min:0'],
            'lines.*.quantity_damaged' => ['nullable', 'numeric', 'min:0'],
            'lines.*.batch_number' => ['nullable', 'string', 'max:100'],
            'lines.*.serial_numbers' => ['nullable', 'array'],
        ]);

        $warehouse = Warehouse::query()->findOrFail($validated['warehouse_id'] ?? $purchaseOrder->delivery_warehouse_id);
        abort_unless($warehouse->tenant_id === $tenantId, 404);

        $receipt = $this->receipts->post($purchaseOrder, $warehouse, $validated['lines'], $this->context->user()->id, $validated['notes'] ?? null);

        return $this->ok($receipt, 201);
    }

    public function show(GoodsReceipt $goodsReceipt)
    {
        $this->authorizeScope($goodsReceipt);

        return $this->ok($goodsReceipt->load(['warehouse', 'partner', 'purchaseOrder', 'items.product']));
    }

    private function authorizeScope(GoodsReceipt $receipt): void
    {
        abort_unless($receipt->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessWarehouse($this->context->user(), $this->context->tenantId(), $receipt->warehouse_id),
            403,
            'This warehouse is outside your assigned data scope.'
        );
    }
}
