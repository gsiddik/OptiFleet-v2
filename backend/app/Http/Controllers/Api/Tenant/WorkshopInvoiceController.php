<?php

namespace App\Http\Controllers\Api\Tenant;

use App\Domain\AccessControl\Services\DataScopeService;
use App\Domain\Configuration\Services\DocumentPdfService;
use App\Domain\Configuration\Services\DocumentTemplateContextBuilder;
use App\Domain\Configuration\Services\DocumentTemplateRenderService;
use App\Domain\WorkOrder\Models\WorkOrder;
use App\Domain\WorkOrder\Models\WorkOrderExternalService;
use App\Domain\WorkOrder\Models\WorkshopInvoice;
use App\Domain\WorkOrder\Models\WorkshopInvoiceCancellation;
use App\Domain\WorkOrder\Models\WorkshopInvoiceCorrection;
use App\Domain\WorkOrder\Services\WorkshopInvoiceReconciliationService;
use App\Domain\WorkOrder\Services\WorkshopInvoiceService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

/**
 * R1: Workshop Invoice is an externally-issued document OptiFleet records,
 * never issues — every endpoint here is named accordingly (record/receive,
 * never "create/issue an invoice").
 */
class WorkshopInvoiceController extends Controller
{
    public function __construct(
        private readonly WorkshopInvoiceService $invoices,
        private readonly WorkshopInvoiceReconciliationService $reconciliation,
        private readonly DataScopeService $scope,
        private readonly TenantContext $context,
    ) {}

    public function index(Request $request)
    {
        $query = WorkshopInvoice::query()->with(['partner', 'workOrder', 'memo', 'payment']);

        foreach (['status', 'partner_id', 'work_order_id'] as $filter) {
            if ($value = $request->string($filter)->value()) {
                $query->where($filter, $value);
            }
        }
        if ($from = $request->string('invoice_date_from')->value()) {
            $query->where('invoice_date', '>=', $from);
        }
        if ($to = $request->string('invoice_date_to')->value()) {
            $query->where('invoice_date', '<=', $to);
        }

        return $this->paginated($query->latest('received_at')->paginate($request->integer('per_page', 20)));
    }

    public function show(WorkshopInvoice $workshopInvoice)
    {
        $this->authorizeInvoiceScope($workshopInvoice);

        return $this->ok($workshopInvoice->load(['partner', 'workOrder', 'memo', 'payment', 'corrections', 'cancellations']));
    }

    /** Record an externally-issued Workshop Invoice against a COMPLETED Maintenance Memo. */
    public function record(Request $request, WorkOrder $workOrder, WorkOrderExternalService $externalService)
    {
        $this->authorizeWorkOrderScope($workOrder);
        abort_unless($externalService->work_order_id === $workOrder->id, 404);

        $validated = $request->validate([
            'external_invoice_number' => ['required', 'string', 'max:255'],
            'invoice_date' => ['required', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:invoice_date'],
            'currency' => ['nullable', 'string', 'max:8'],
            'subtotal' => ['nullable', 'numeric', 'min:0'],
            'tax_total' => ['nullable', 'numeric', 'min:0'],
            'discount_total' => ['nullable', 'numeric', 'min:0'],
            'total_amount' => ['required', 'numeric', 'gt:0'],
            'line_items' => ['nullable', 'array'],
            'line_items.*.description' => ['required_with:line_items', 'string'],
            'line_items.*.quantity' => ['nullable', 'numeric'],
            'line_items.*.unit_price' => ['nullable', 'numeric'],
            'line_items.*.line_total' => ['nullable', 'numeric'],
            'partner_reference' => ['nullable', 'string', 'max:255'],
            'returned_memo_attachment_url' => ['nullable', 'string', 'max:255'],
            'invoice_attachment_url' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->ok($this->invoices->record($externalService, $validated, $this->context->user()->id), 201);
    }

    public function reconciliation(WorkshopInvoice $workshopInvoice)
    {
        $this->authorizeInvoiceScope($workshopInvoice);

        return $this->ok($this->reconciliation->reconcile($workshopInvoice));
    }

    public function updateReconciliationNote(Request $request, WorkshopInvoice $workshopInvoice)
    {
        $this->authorizeInvoiceScope($workshopInvoice);
        $validated = $request->validate(['reconciliation_note' => ['nullable', 'string']]);

        $workshopInvoice->update(['reconciliation_note' => $validated['reconciliation_note'] ?? null]);

        return $this->ok($workshopInvoice->fresh());
    }

    public function requestCorrection(Request $request, WorkshopInvoice $workshopInvoice)
    {
        $this->authorizeInvoiceScope($workshopInvoice);
        $validated = $request->validate([
            'requested_values' => ['required', 'array', 'min:1'],
            'requested_values.external_invoice_number' => ['sometimes', 'string', 'max:255'],
            'requested_values.invoice_date' => ['sometimes', 'date'],
            'requested_values.due_date' => ['sometimes', 'nullable', 'date'],
            'requested_values.currency' => ['sometimes', 'string', 'max:8'],
            'requested_values.subtotal' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'requested_values.tax_total' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'requested_values.discount_total' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'requested_values.total_amount' => ['sometimes', 'numeric', 'gt:0'],
            'requested_values.partner_reference' => ['sometimes', 'nullable', 'string', 'max:255'],
            'requested_values.notes' => ['sometimes', 'nullable', 'string'],
            'reason' => ['required', 'string'],
        ]);

        return $this->ok($this->invoices->requestCorrection(
            $workshopInvoice, $validated['requested_values'], $validated['reason'], $this->context->user()->id,
        ), 201);
    }

    public function decideCorrection(Request $request, WorkshopInvoice $workshopInvoice, WorkshopInvoiceCorrection $correction)
    {
        $this->authorizeInvoiceScope($workshopInvoice);
        abort_unless($correction->workshop_invoice_id === $workshopInvoice->id, 404);

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:APPROVE,REJECT'],
            'note' => ['nullable', 'string'],
        ]);

        return $this->ok($this->invoices->decideCorrection(
            $correction, $validated['decision'], $this->context->user()->id, $validated['note'] ?? null,
        ));
    }

    public function requestCancellation(Request $request, WorkshopInvoice $workshopInvoice)
    {
        $this->authorizeInvoiceScope($workshopInvoice);
        $validated = $request->validate(['reason' => ['required', 'string']]);

        return $this->ok($this->invoices->requestCancellation(
            $workshopInvoice, $validated['reason'], $this->context->user()->id,
        ), 201);
    }

    public function decideCancellation(Request $request, WorkshopInvoice $workshopInvoice, WorkshopInvoiceCancellation $cancellation)
    {
        $this->authorizeInvoiceScope($workshopInvoice);
        abort_unless($cancellation->workshop_invoice_id === $workshopInvoice->id, 404);

        $validated = $request->validate([
            'decision' => ['required', 'string', 'in:APPROVE,REJECT'],
            'note' => ['nullable', 'string'],
        ]);

        return $this->ok($this->invoices->decideCancellation(
            $cancellation, $validated['decision'], $this->context->user()->id, $validated['note'] ?? null,
        ));
    }

    public function recordPayment(Request $request, WorkshopInvoice $workshopInvoice)
    {
        $this->authorizeInvoiceScope($workshopInvoice);
        $validated = $request->validate([
            'payment_date' => ['required', 'date'],
            'paid_amount' => ['required', 'numeric', 'gt:0'],
            'payment_method' => ['nullable', 'string', 'max:255'],
            'reference_number' => ['nullable', 'string', 'max:255'],
            'evidence_url' => ['required', 'string', 'max:255'],
            'notes' => ['nullable', 'string'],
        ]);

        return $this->ok($this->invoices->recordPayment($workshopInvoice, $validated, $this->context->user()->id), 201);
    }

    public function print(WorkshopInvoice $workshopInvoice, DocumentTemplateRenderService $templates, DocumentPdfService $pdf)
    {
        $this->authorizeInvoiceScope($workshopInvoice);

        $context = DocumentTemplateContextBuilder::forWorkshopInvoice($workshopInvoice);
        $rendered = $templates->render(
            'workshop_invoice', $context, $workshopInvoice->tenant_id,
            $workshopInvoice->workOrder?->branch_id, $workshopInvoice->workOrder?->workshop_id,
        );

        return response($pdf->fromHtml($rendered['html']), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="workshop-invoice-'.$workshopInvoice->id.'.pdf"',
        ]);
    }

    private function authorizeWorkOrderScope(WorkOrder $workOrder): void
    {
        abort_unless($workOrder->tenant_id === $this->context->tenantId(), 404);
        abort_unless(
            $this->scope->canAccessWorkshop($this->context->user(), $this->context->tenantId(), $workOrder->workshop_id),
            403,
            'This Work Order is outside your assigned data scope.'
        );
    }

    private function authorizeInvoiceScope(WorkshopInvoice $invoice): void
    {
        abort_unless($invoice->tenant_id === $this->context->tenantId(), 404);
        $workOrder = $invoice->workOrder ?? WorkOrder::query()->find($invoice->work_order_id);
        abort_unless(
            ! $workOrder || $this->scope->canAccessWorkshop($this->context->user(), $this->context->tenantId(), $workOrder->workshop_id),
            403,
            'This Workshop Invoice is outside your assigned data scope.'
        );
    }
}
