<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Configuration\Services\DocumentPdfService;
use App\Domain\Configuration\Services\DocumentTemplateContextBuilder;
use App\Domain\Configuration\Services\DocumentTemplateRenderService;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseReturn;
use App\Domain\Procurement\Services\PurchaseReturnService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/** Purchase Order Return to Vendor (Return Orders): create, vendor decision, redelivery, print. */
class PurchaseReturnController extends Controller
{
    public function __construct(
        private readonly PurchaseReturnService $returns,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function store(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->authorizeOrder($purchaseOrder);
        $validated = $request->validate([
            'return_option' => ['required', 'string', 'in:'.implode(',', PurchaseReturn::OPTIONS)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.purchase_order_item_id' => ['required', 'uuid'],
            'items.*.quantity' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->ok($this->returns->create($purchaseOrder, $validated['return_option'], $validated['items'], $validated['notes'] ?? null, $this->context->user()->id), 201);
    }

    public function accept(Request $request, PurchaseReturn $purchaseReturn)
    {
        $this->authorizeReturn($purchaseReturn);

        return $this->ok($this->returns->accept($purchaseReturn, $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null, $this->context->user()->id));
    }

    public function reject(Request $request, PurchaseReturn $purchaseReturn)
    {
        $this->authorizeReturn($purchaseReturn);

        return $this->ok($this->returns->reject($purchaseReturn, $request->validate(['note' => ['nullable', 'string', 'max:1000']])['note'] ?? null, $this->context->user()->id));
    }

    public function receiveRedelivery(PurchaseReturn $purchaseReturn)
    {
        $this->authorizeReturn($purchaseReturn);

        return $this->ok($this->returns->receiveRedelivery($purchaseReturn, $this->context->user()->id));
    }

    /**
     * Print Return Order: the tenant's published Return Order template as a PDF. Generating it
     * records that the Return Order was printed (a redelivery request becomes ready to receive).
     */
    public function print(PurchaseReturn $purchaseReturn, DocumentTemplateRenderService $templates, DocumentPdfService $pdf)
    {
        $this->authorizeReturn($purchaseReturn);
        $context = DocumentTemplateContextBuilder::forPurchaseReturn($purchaseReturn);
        $rendered = $templates->render('purchase_return', $context, $purchaseReturn->tenant_id, null, null, $purchaseReturn->warehouse_id);
        $body = $pdf->fromHtml($rendered['html']);
        $this->returns->markPrinted($purchaseReturn, $this->context->user()->id);

        return response($body, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace('/', '-', $purchaseReturn->return_number).'.pdf"',
        ]);
    }

    private function authorizeOrder(PurchaseOrder $po): void
    {
        abort_unless($po->tenant_id === $this->context->tenantId(), 404);
        abort_unless($this->scope->canAccessWarehouse($this->context->user(), $this->context->tenantId(), $po->delivery_warehouse_id), 403, 'This purchase order is outside your assigned data scope.');
    }

    private function authorizeReturn(PurchaseReturn $return): void
    {
        abort_unless($return->tenant_id === $this->context->tenantId(), 404);
        $this->authorizeOrder(PurchaseOrder::query()->findOrFail($return->purchase_order_id));
    }
}
