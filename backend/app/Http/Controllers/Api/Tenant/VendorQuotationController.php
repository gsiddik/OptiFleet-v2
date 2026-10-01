<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Partner\Models\Partner;
use App\Domain\Procurement\Models\Rfq;
use App\Domain\Procurement\Models\VendorQuotation;
use App\Domain\Procurement\Services\RfqService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VendorQuotationController extends Controller
{
    public function __construct(
        private readonly RfqService $rfqs,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        // RFQ number shown in the list comes from one eager-loaded query (no N+1), and the list only
        // shows quotations of RFQs whose warehouse is inside the user's data scope.
        $query = VendorQuotation::query()->where('tenant_id', $tenantId)
            ->with(['partner', 'rfq:id,rfq_number,warehouse_id,status', 'items.product']);
        $allowedRfqs = Rfq::query()->where('tenant_id', $tenantId)->select('id');
        $this->scope->applyWarehouseScope($allowedRfqs, $this->context->user(), $tenantId, 'warehouse_id');
        $query->whereIn('rfq_id', $allowedRfqs);

        if ($rfqId = $request->string('rfq_id')->value()) {
            $query->where('rfq_id', $rfqId);
        }
        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->latest('created_at')->paginate($request->integer('per_page', 20)));
    }

    public function store(Request $request, Rfq $rfq)
    {
        $this->authorizeScopeRfq($rfq);
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'partner_id' => ['required', 'uuid', 'exists:partners,id'],
            'quotation_number' => ['nullable', 'string', 'max:100'],
            'validity_date' => ['nullable', 'date'],
            'lead_time_days' => ['nullable', 'integer', 'min:0'],
            'payment_terms' => ['nullable', 'string', 'max:100'],
            'freight_cost' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.rfq_item_id' => ['nullable', 'uuid', 'exists:rfq_items,id'],
            'items.*.product_id' => ['required', 'uuid', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['required', 'numeric', 'min:0'],
            'items.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'items.*.tax_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            // Type is checked from the file content by QuotationAttachmentService (PDF/DOC/DOCX).
            'attachment' => ['required', 'file', 'max:10240'],
        ], [
            'attachment.required' => 'Upload the vendor\'s quotation document (PDF, DOC or DOCX) before recording the quotation.',
            'attachment.max' => 'The quotation document may not be larger than 10 MB.',
        ]);

        $partner = Partner::query()->findOrFail($validated['partner_id']);
        abort_unless($partner->tenant_id === $tenantId, 404);

        $quotation = $this->rfqs->submitQuotation($rfq, $partner, $validated, $validated['items'], $request->file('attachment'), $this->context->user()->id);

        return $this->ok($quotation, 201);
    }

    public function show(VendorQuotation $quotation)
    {
        $this->authorizeScopeQuotation($quotation);

        return $this->ok($quotation->load(['partner', 'rfq', 'items.product']));
    }

    /** View (inline) or download the vendor's quotation document — authorized, never a public URL. */
    public function attachment(Request $request, VendorQuotation $quotation)
    {
        $this->authorizeScopeQuotation($quotation);
        abort_unless($quotation->attachment_path !== null, 404, 'This quotation has no document attached.');

        $disposition = $request->boolean('download') ? 'attachment' : 'inline';
        $filename = str_replace(['"', "\r", "\n"], '', $quotation->attachment_original_filename ?? 'quotation');

        return Storage::disk($quotation->attachment_disk)->response($quotation->attachment_path, $filename, [
            'Content-Type' => $quotation->attachment_mime_type,
            'X-Content-Type-Options' => 'nosniff',
        ], $disposition);
    }

    public function select(VendorQuotation $quotation)
    {
        $this->authorizeScopeQuotation($quotation);

        return $this->ok($this->rfqs->selectVendor($quotation));
    }

    private function authorizeScopeRfq(Rfq $rfq): void
    {
        abort_unless($rfq->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessWarehouse($this->context->user(), $this->context->tenantId(), $rfq->warehouse_id),
            403,
            'This RFQ is outside your assigned data scope.'
        );
    }

    private function authorizeScopeQuotation(VendorQuotation $quotation): void
    {
        abort_unless($quotation->tenant_id === $this->context->tenantId(), 404);
        $rfq = Rfq::query()->find($quotation->rfq_id);
        if ($rfq) {
            $this->authorizeScopeRfq($rfq);
        }
    }
}
