<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Configuration\Services\DocumentPdfService;
use App\Domain\Configuration\Services\DocumentTemplateContextBuilder;
use App\Domain\Configuration\Services\DocumentTemplateRenderService;
use App\Domain\Organization\Models\Warehouse;
use App\Domain\Partner\Models\Partner;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\VendorQuotation;
use App\Domain\Procurement\Services\PurchaseOrderService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $orders,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $query = PurchaseOrder::query()->where('tenant_id', $tenantId)->with(['partner', 'deliveryWarehouse']);
        $this->scope->applyWarehouseScope($query, $this->context->user(), $tenantId, 'delivery_warehouse_id');

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($partnerId = $request->string('partner_id')->value()) {
            $query->where('partner_id', $partnerId);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'partner_id' => ['required', 'uuid', 'exists:partners,id'],
            'delivery_warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            'expected_delivery_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string'],
            'freight_cost' => ['nullable', 'numeric', 'min:0'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'items.*.quantity_ordered' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
        ]);

        $partner = Partner::query()->findOrFail($validated['partner_id']);
        $warehouse = Warehouse::query()->findOrFail($validated['delivery_warehouse_id']);
        abort_unless($partner->tenant_id === $tenantId && $warehouse->tenant_id === $tenantId, 404);
        abort_unless($this->scope->canAccessWarehouse($this->context->user(), $tenantId, $warehouse->id), 403);

        $po = $this->orders->create($partner, $warehouse, [
            'expected_delivery_date' => $validated['expected_delivery_date'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'freight_cost' => $validated['freight_cost'] ?? 0,
        ], $validated['items'], $this->context->user()->id);

        return $this->ok($po, 201);
    }

    public function storeFromQuotation(Request $request, VendorQuotation $quotation)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'delivery_warehouse_id' => ['required', 'uuid', 'exists:warehouses,id'],
            // Expected Receipt Date is derived by the service (Order Date + quotation Lead Days).
            'order_date' => ['required', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string'],
        ]);

        abort_unless($quotation->tenant_id === $tenantId, 404);
        $warehouse = Warehouse::query()->findOrFail($validated['delivery_warehouse_id']);
        abort_unless($warehouse->tenant_id === $tenantId, 404);
        abort_unless($this->scope->canAccessWarehouse($this->context->user(), $tenantId, $warehouse->id), 403);

        $po = $this->orders->createFromQuotation($quotation, $warehouse, $validated, $this->context->user()->id);

        return $this->ok($po, 201);
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $this->authorizeScope($purchaseOrder);

        // Receipt history: every Goods Receipt (oldest first) with its received quantities and the
        // vendor invoice it was received against (several receipts may share one invoice).
        return $this->ok($purchaseOrder->load([
            'partner', 'deliveryWarehouse', 'items.product', 'workflowApprovalRequest.steps',
            'goodsReceipts' => fn ($q) => $q->orderBy('received_at')->orderBy('created_at'),
            'goodsReceipts.items.product:id,name,sku',
            'goodsReceipts.receiver:id,name',
            'goodsReceipts.vendorInvoiceReference',
        ]));
    }

    /**
     * Section 13: renders the tenant's effective published Purchase Order
     * template into a PDF, preserving the document number/numbering config
     * version/template version used at generation time.
     */
    public function print(PurchaseOrder $purchaseOrder, DocumentTemplateRenderService $templates, DocumentPdfService $pdf)
    {
        $this->authorizeScope($purchaseOrder);

        $context = DocumentTemplateContextBuilder::forPurchaseOrder($purchaseOrder);
        $rendered = $templates->render('purchase_order', $context, $purchaseOrder->tenant_id, null, null, $purchaseOrder->delivery_warehouse_id);

        return response($pdf->fromHtml($rendered['html']), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$purchaseOrder->po_number.'.pdf"',
        ]);
    }

    public function submit(PurchaseOrder $purchaseOrder)
    {
        return $this->transition($purchaseOrder, 'SUBMITTED');
    }

    public function approve(PurchaseOrder $purchaseOrder)
    {
        $this->authorizeScope($purchaseOrder);

        return $this->ok($this->orders->approve($purchaseOrder, $this->context->user()->id));
    }

    public function reject(PurchaseOrder $purchaseOrder)
    {
        return $this->transition($purchaseOrder, 'REJECTED');
    }

    /** G-06: decides the next pending step of a PO's in-flight tiered approval (only reachable when one exists). */
    public function decideApproval(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->authorizeScope($purchaseOrder);
        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:APPROVED,REJECTED'],
            'note' => ['nullable', 'string'],
        ]);

        return $this->ok($this->orders->decideApproval(
            $purchaseOrder, $validated['decision'], $this->context->user()->id, $validated['note'] ?? null,
        ));
    }

    public function issue(PurchaseOrder $purchaseOrder)
    {
        return $this->transition($purchaseOrder, 'ISSUED');
    }

    public function close(PurchaseOrder $purchaseOrder)
    {
        return $this->transition($purchaseOrder, 'CLOSED');
    }

    public function cancel(PurchaseOrder $purchaseOrder)
    {
        return $this->transition($purchaseOrder, 'CANCELLED');
    }

    private function transition(PurchaseOrder $purchaseOrder, string $to)
    {
        $this->authorizeScope($purchaseOrder);

        return $this->ok($this->orders->transition($purchaseOrder, $to));
    }

    private function authorizeScope(PurchaseOrder $po): void
    {
        abort_unless($po->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessWarehouse($this->context->user(), $this->context->tenantId(), $po->delivery_warehouse_id),
            403,
            'This purchase order is outside your assigned data scope.'
        );
    }
}
