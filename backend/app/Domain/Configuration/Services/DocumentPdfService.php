<?php

namespace App\Domain\Configuration\Services;

use Dompdf\Dompdf;
use Dompdf\Options;

/**
 * Section 13: generic HTML -> PDF rendering for any configurable document
 * template, mirroring the existing InvoicePdfService's Dompdf setup
 * (remote resources disabled, no JS/PHP execution surface).
 */
class DocumentPdfService
{
    public function fromHtml(string $html): string
    {
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
