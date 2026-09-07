<?php

namespace App\Http\Controllers\Api\Platform;

use App\Domain\Invoice\Models\Invoice;
use App\Domain\Invoice\Services\InvoicePdfService;
use App\Domain\Invoice\Services\InvoiceService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class InvoiceController extends Controller
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly InvoicePdfService $pdf,
    ) {}

    public function index(Request $request)
    {
        $query = Invoice::query()->with('tenant');

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }
        if ($tenantId = $request->string('tenant_id')->value()) {
            $query->where('tenant_id', $tenantId);
        }

        return $this->paginated($query->latest('invoice_date')->paginate($request->integer('per_page', 15)));
    }

    public function show(Invoice $invoice)
    {
        return $this->ok($invoice->load(['items', 'tenant', 'contract', 'payments']));
    }

    public function void(Request $request, Invoice $invoice)
    {
        $updated = $this->invoices->void($invoice, $request->string('reason', 'Voided by platform')->value());

        return $this->ok($updated);
    }

    public function downloadPdf(Invoice $invoice)
    {
        $pdf = $this->pdf->render($invoice);

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.str_replace('/', '-', $invoice->invoice_number).'.pdf"',
        ]);
    }
}
