<?php

namespace App\Http\Controllers\Api\Tenant\Account;

use App\Domain\Invoice\Models\Invoice;
use App\Domain\Invoice\Services\InvoicePdfService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;

class AccountInvoiceController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly InvoicePdfService $pdf,
    ) {}

    public function index(Request $request)
    {
        $query = Invoice::query()->where('tenant_id', $this->context->tenantId());

        if ($status = $request->string('status')->value()) {
            $query->where('status', $status);
        }

        return $this->paginated($query->latest('invoice_date')->paginate($request->integer('per_page', 15)));
    }

    public function show(Invoice $invoice)
    {
        $this->authorizeOwnership($invoice);

        return $this->ok($invoice->load(['items', 'payments']));
    }

    public function downloadPdf(Invoice $invoice)
    {
        $this->authorizeOwnership($invoice);

        // Document locale: an explicit ?locale=, else the request locale (user → tenant → Accept-Language → en).
        $pdf = $this->pdf->render($invoice, \App\Domain\DocumentGeneration\Support\DocumentLocale::resolve(request()->query('locale'), null, app()->getLocale()));

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.str_replace('/', '-', $invoice->invoice_number).'.pdf"',
        ]);
    }

    private function authorizeOwnership(Invoice $invoice): void
    {
        abort_unless($invoice->tenant_id === $this->context->tenantId(), 404);
    }
}
