<?php

namespace App\Support\Spreadsheet\Import;

/**
 * A definition whose template must be identified on upload (e.g. a Product template is specific to one
 * Item Type): the engine writes marker() on row 2 of "How To" and, on upload, requires that exact cell —
 * a template generated for another variant (a TIRE template uploaded as RIM) is rejected before any row
 * is read. The marker is a stable, language-neutral code.
 */
interface IdentifiesTemplate
{
    public function marker(): string;

    /** The rejection message when the uploaded workbook carries another (or no) marker. */
    public function markerMismatchMessage(?string $found): string;
}
