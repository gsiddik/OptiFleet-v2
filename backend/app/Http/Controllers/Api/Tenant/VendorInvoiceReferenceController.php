<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Procurement\Models\GoodsReceipt;
use App\Domain\Procurement\Models\VendorInvoiceReference;
use App\Domain\Procurement\Services\VendorInvoicePaymentService;
use App\Domain\Procurement\Support\VendorInvoiceStatus;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class VendorInvoiceReferenceController extends Controller
{
    public function __construct(
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    /**
     * Vendor Invoice References: one row per Goods Receipt that was received against an invoice
     * (GR is the row granularity — an invoice shared by several receipts appears on each of
     * their rows, always with the same invoice data and status). Legacy invoices never linked
     * to a receipt are not listed (kept, still readable via show).
     */
    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $validated = $request->validate([
            'status' => ['nullable', 'in:'.implode(',', VendorInvoiceStatus::STATUSES)],
            'partner_id' => ['nullable', 'uuid'],
            'purchase_order_id' => ['nullable', 'uuid'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);
        $today = $this->today($tenantId);

        $query = GoodsReceipt::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('vendor_invoice_reference_id')
            ->with(['purchaseOrder:id,po_number', 'vendorInvoiceReference.partner:id,name', 'vendorInvoiceReference.payment.payer:id,name']);
        $this->scope->applyWarehouseScope($query, $this->context->user(), $tenantId, 'warehouse_id');

        if (! empty($validated['status'])) {
            $query->whereHas('vendorInvoiceReference', fn ($q) => VendorInvoiceStatus::constrain($q, $validated['status'], $today, $this->isPaid(...)));
        }
        if (! empty($validated['partner_id'])) {
            $query->where('partner_id', $validated['partner_id']);
        }
        if (! empty($validated['purchase_order_id'])) {
            $query->where('purchase_order_id', $validated['purchase_order_id']);
        }
        if ($term = trim((string) ($validated['search'] ?? ''))) {
            $like = '%'.mb_strtolower($term).'%';
            $query->where(fn ($q) => $q
                ->whereRaw('LOWER(gr_number) LIKE ?', [$like])
                ->orWhereHas('purchaseOrder', fn ($po) => $po->whereRaw('LOWER(po_number) LIKE ?', [$like]))
                ->orWhereHas('vendorInvoiceReference', fn ($inv) => $inv->whereRaw('LOWER(vendor_invoice_number) LIKE ?', [$like]))
                ->orWhereHas('partner', fn ($p) => $p->whereRaw('LOWER(name) LIKE ?', [$like])));
        }

        $page = $query->orderByDesc('received_at')->orderByDesc('created_at')->orderByDesc('gr_number')->paginate($request->integer('per_page', 20));
        $page->through(fn (GoodsReceipt $gr) => [
            'id' => $gr->id,
            'gr_number' => $gr->gr_number,
            'received_at' => $gr->received_at,
            'purchase_order' => $gr->purchaseOrder ? ['id' => $gr->purchaseOrder->id, 'po_number' => $gr->purchaseOrder->po_number] : null,
            'invoice' => $this->present($gr->vendorInvoiceReference, $today),
        ]);

        return $this->paginated($page);
    }

    public function show(VendorInvoiceReference $vendorInvoiceReference)
    {
        $this->authorizeScope($vendorInvoiceReference);

        $vendorInvoiceReference->load(['partner', 'purchaseOrder', 'payment.payer:id,name', 'goodsReceipts:id,gr_number,received_at,vendor_invoice_reference_id']);

        return $this->ok($this->present($vendorInvoiceReference, $this->today($vendorInvoiceReference->tenant_id)) + [
            'purchase_order' => $vendorInvoiceReference->purchaseOrder,
            'goods_receipts' => $vendorInvoiceReference->goodsReceipts,
        ]);
    }

    public function download(VendorInvoiceReference $vendorInvoiceReference)
    {
        $this->authorizeScope($vendorInvoiceReference);
        abort_unless($vendorInvoiceReference->attachment_path, 404);
        $name = $vendorInvoiceReference->attachment_original_name ?: 'invoice-'.$vendorInvoiceReference->vendor_invoice_number.'.pdf';

        // inline: opens in the browser's PDF viewer; the client can still save it under $name.
        return Storage::disk($vendorInvoiceReference->attachment_disk)->response($vendorInvoiceReference->attachment_path, $name);
    }

    /**
     * Payment (full settlement) of the invoice — never of a single receipt row. Multipart:
     * payment_date, amount, payment_proof (JPG/JPEG/PNG/PDF).
     */
    public function pay(Request $request, VendorInvoiceReference $vendorInvoiceReference, VendorInvoicePaymentService $payments)
    {
        $this->authorizeScope($vendorInvoiceReference);
        $validated = $request->validate([
            'payment_date' => ['required', 'date'],
            'amount' => ['required', 'regex:/^\d{1,14}(\.\d{1,2})?$/', 'numeric', 'gt:0'],
            'payment_proof' => ['required', 'file', 'mimes:jpg,jpeg,png,pdf', 'max:10240'],
        ], [
            'amount.regex' => 'The amount must be a number with up to 2 decimals.',
            'payment_proof.mimes' => 'The payment proof must be a JPG, JPEG, PNG or PDF file.',
        ]);

        $payment = $payments->pay($vendorInvoiceReference, $validated['payment_date'], $validated['amount'], $request->file('payment_proof'), $this->context->user()->id);

        return $this->ok($payment, 201);
    }

    /** The payment proof, inline (image preview / PDF viewer) under its original name. */
    public function paymentProof(VendorInvoiceReference $vendorInvoiceReference)
    {
        $this->authorizeScope($vendorInvoiceReference);
        $payment = $vendorInvoiceReference->payment;
        abort_unless($payment !== null, 404);

        return Storage::disk($payment->proof_disk)->response($payment->proof_path, $payment->proof_original_name ?: 'payment-proof', ['Content-Type' => $payment->proof_mime_type ?: 'application/octet-stream']);
    }

    /** Invoice fields shown on every row that uses it, with its derived status. */
    private function present(VendorInvoiceReference $invoice, CarbonImmutable $today): array
    {
        return [
            'id' => $invoice->id,
            'vendor_invoice_number' => $invoice->vendor_invoice_number,
            'vendor_invoice_date' => $invoice->vendor_invoice_date?->toDateString(),
            'amount' => $invoice->amount,
            'terms_of_payment_days' => $invoice->terms_of_payment_days,
            'due_date' => $invoice->due_date?->toDateString(),
            'has_document' => $invoice->has_document,
            'attachment_original_name' => $invoice->attachment_original_name,
            'partner' => $invoice->partner ? ['id' => $invoice->partner->id, 'name' => $invoice->partner->name] : null,
            'status' => VendorInvoiceStatus::resolve($invoice->due_date, $invoice->payment !== null, $today),
            'payment' => $invoice->payment ? [
                'payment_date' => $invoice->payment->payment_date->toDateString(),
                'amount' => $invoice->payment->amount,
                'proof_original_name' => $invoice->payment->proof_original_name,
                'proof_mime_type' => $invoice->payment->proof_mime_type,
                'paid_by' => $invoice->payment->payer?->name,
                'recorded_at' => $invoice->payment->created_at,
            ] : null,
        ];
    }

    private function isPaid(Builder $query, bool $paid): void
    {
        $paid ? $query->whereHas('payment') : $query->whereDoesntHave('payment');
    }

    private function today(string $tenantId): CarbonImmutable
    {
        return VendorInvoiceStatus::today(Tenant::query()->whereKey($tenantId)->value('timezone'));
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
