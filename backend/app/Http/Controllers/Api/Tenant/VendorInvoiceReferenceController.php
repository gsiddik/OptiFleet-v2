<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Procurement\Models\VendorInvoiceReference;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VendorInvoiceReferenceController extends Controller
{
    public function __construct(
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $query = VendorInvoiceReference::query()->where('tenant_id', $this->context->tenantId())->with(['partner', 'purchaseOrder', 'goodsReceipt']);

        if ($partnerId = $request->string('partner_id')->value()) {
            $query->where('partner_id', $partnerId);
        }
        if ($poId = $request->string('purchase_order_id')->value()) {
            $query->where('purchase_order_id', $poId);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 20)));
    }

    public function show(VendorInvoiceReference $vendorInvoiceReference)
    {
        $this->authorizeScope($vendorInvoiceReference);

        return $this->ok($vendorInvoiceReference->load(['partner', 'purchaseOrder', 'goodsReceipt']));
    }

    public function download(VendorInvoiceReference $vendorInvoiceReference)
    {
        $this->authorizeScope($vendorInvoiceReference);
        abort_unless($vendorInvoiceReference->attachment_path, 404);
        $name = $vendorInvoiceReference->attachment_original_name ?: 'invoice-'.$vendorInvoiceReference->vendor_invoice_number.'.pdf';

        // inline: opens in the browser's PDF viewer; the client can still save it under $name.
        return Storage::disk($vendorInvoiceReference->attachment_disk)->response($vendorInvoiceReference->attachment_path, $name);
    }

    public function updateStatus(Request $request, VendorInvoiceReference $vendorInvoiceReference)
    {
        $this->authorizeScope($vendorInvoiceReference);
        $validated = $request->validate(['status' => ['required', 'in:RECEIVED,VERIFIED,DISPUTED']]);
        $vendorInvoiceReference->update($validated);

        return $this->ok($vendorInvoiceReference->fresh());
    }

    /** Tenant isolation, plus the warehouse data scope of the Purchase Order it belongs to. */
    private function authorizeScope(VendorInvoiceReference $reference): void
    {
        $tenantId = $this->context->tenantId();
        abort_unless($reference->tenant_id === $tenantId, 404);
        $warehouseId = $reference->purchaseOrder?->delivery_warehouse_id;
        abort_unless(
            $warehouseId === null || $this->scope->canAccessWarehouse($this->context->user(), $tenantId, $warehouseId),
            403,
            'This warehouse is outside your assigned data scope.'
        );
    }
}
