<?php

namespace Database\Seeders;

use Illuminate\Http\UploadedFile;

/**
 * Demo/functional seeders only: a minimal, valid one-page PDF standing in for a vendor
 * document (quotation by default; also used for demo vendor invoices recorded at Goods
 * Receipt). Never used by the production baseline seeders.
 */
final class DemoQuotationDocument
{
    public static function make(string $label, string $title = 'Demo vendor quotation', string $filename = 'demo-quotation.pdf'): UploadedFile
    {
        $text = str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], "{$title} - {$label}");
        $stream = "BT /F1 12 Tf 72 720 Td ({$text}) Tj ET";
        $objects = [
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
            '<< /Length '.strlen($stream)." >>\nstream\n{$stream}\nendstream",
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
        ];
        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $i => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($i + 1)." 0 obj\n{$object}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= "trailer\n<< /Size ".(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        $path = tempnam(sys_get_temp_dir(), 'quote');
        file_put_contents($path, $pdf);

        return new UploadedFile($path, $filename, 'application/pdf', null, true);
    }
}
