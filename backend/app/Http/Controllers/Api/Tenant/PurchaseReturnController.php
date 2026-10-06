<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Configuration\Services\DocumentTemplateContextBuilder;
use App\Domain\DocumentGeneration\Support\DocumentSource;
use App\Domain\Procurement\Models\PurchaseOrder;
use App\Domain\Procurement\Models\PurchaseReturn;
use App\Domain\Procurement\Services\PurchaseReturnService;
use App\Http\Controllers\Concerns\PrintsDocuments;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/** Purchase Order Return to Vendor (Return Orders): create, vendor decision, redelivery, print. */
class PurchaseReturnController extends Controller
{
    use PrintsDocuments;

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
            'items.*.component_asset_ids' => ['nullable', 'array'],
            'items.*.component_asset_ids.*' => ['uuid', 'distinct'],
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
    public function print(Request $request, PurchaseReturn $purchaseReturn)
    {
        $this->authorizeReturn($purchaseReturn);
        $response = $this->printDocument($request, $this->purchaseReturnDocument($purchaseReturn));
        $this->returns->markPrinted($purchaseReturn, $this->context->user()->id);

        return $response;
    }

    public function printGenerations(PurchaseReturn $purchaseReturn)
    {
        $this->authorizeReturn($purchaseReturn);

        return $this->documentGenerations($this->purchaseReturnDocument($purchaseReturn));
    }

    public function generatePrint(Request $request, PurchaseReturn $purchaseReturn)
    {
        $this->authorizeReturn($purchaseReturn);

        return $this->generateDocument($request, $this->purchaseReturnDocument($purchaseReturn));
    }

    private function purchaseReturnDocument(PurchaseReturn $purchaseReturn): DocumentSource
    {
        return new DocumentSource(
            'purchase_return', 'purchase_return', $purchaseReturn->id, $purchaseReturn->tenant_id, str_replace('/', '-', $purchaseReturn->return_number).'.pdf',
            fn (string $locale) => DocumentTemplateContextBuilder::forPurchaseReturn($purchaseReturn, $locale),
            warehouseId: $purchaseReturn->warehouse_id,
        );
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
