<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\Partner\Models\Partner;
use App\Domain\Procurement\Models\VendorInvoiceReference;
use App\Domain\Procurement\Services\VendorInvoiceReferenceService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VendorInvoiceReferenceController extends Controller
{
    public function __construct(
        private readonly VendorInvoiceReferenceService $invoices,
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

    public function store(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'partner_id' => ['required', 'uuid', 'exists:partners,id'],
            'purchase_order_id' => ['nullable', 'uuid', 'exists:purchase_orders,id'],
            'goods_receipt_id' => ['nullable', 'uuid', 'exists:goods_receipts,id'],
            'vendor_invoice_number' => ['required', 'string', 'max:100'],
            'vendor_invoice_date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'attachment' => ['nullable', 'file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf'],
        ]);

        $partner = Partner::query()->findOrFail($validated['partner_id']);
        abort_unless($partner->tenant_id === $tenantId, 404);

        $reference = $this->invoices->create($partner, [
            'purchase_order_id' => $validated['purchase_order_id'] ?? null,
            'goods_receipt_id' => $validated['goods_receipt_id'] ?? null,
            'vendor_invoice_number' => $validated['vendor_invoice_number'],
            'vendor_invoice_date' => $validated['vendor_invoice_date'],
            'amount' => $validated['amount'],
            'notes' => $validated['notes'] ?? null,
        ], $request->file('attachment'));

        return $this->ok($reference, 201);
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

        return Storage::disk($vendorInvoiceReference->attachment_disk)->response($vendorInvoiceReference->attachment_path);
    }

    public function updateStatus(Request $request, VendorInvoiceReference $vendorInvoiceReference)
    {
        $this->authorizeScope($vendorInvoiceReference);
        $validated = $request->validate(['status' => ['required', 'in:RECEIVED,VERIFIED,DISPUTED']]);
        $vendorInvoiceReference->update($validated);

        return $this->ok($vendorInvoiceReference->fresh());
    }

    private function authorizeScope(VendorInvoiceReference $reference): void
    {
        abort_unless($reference->tenant_id === $this->context->tenantId(), 404);
    }
}
