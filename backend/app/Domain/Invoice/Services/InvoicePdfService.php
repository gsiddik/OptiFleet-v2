<?php

namespace App\Domain\Invoice\Services;

use App\Domain\Invoice\Models\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Fixed OptiFleet platform invoice template for Phase 2 (Section 29);
 * Phase 5 will introduce configurable document templates. Rendered
 * on-demand rather than persisted to disk, so it always reflects the
 * invoice's current payment state.
 */
class InvoicePdfService
{
    /** @param string $locale document language (labels, status, dates, amounts); invoice data is printed as stored */
    public function render(Invoice $invoice, string $locale = 'en'): string
    {
        $invoice->loadMissing(['items', 'tenant', 'contract']);

        $html = view('invoices.pdf', ['invoice' => $invoice, 'locale' => $locale])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'sans-serif');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
