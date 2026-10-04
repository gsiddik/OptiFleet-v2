<?php

namespace App\Support\Spreadsheet;

use RuntimeException;
use ZipArchive;

/**
 * Minimal Office Open XML (.xlsx) writer for generated templates: string cells only (inline
 * strings), a bold header row, per-column widths and per-column number formats (text / date) so
 * values typed into the sheet later keep their meaning (e.g. a numeric-looking serial stays text).
 * No spreadsheet library is installed; this writes the few parts Excel / LibreOffice require.
 */
class XlsxWriter
{
    public const MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** Column formats → cellXfs index in styles.xml. */
    public const FORMAT_GENERAL = 0;

    public const FORMAT_TEXT = 1;

    public const FORMAT_DATE = 3;

    private const STYLE_BOLD = 2;

    /** @var list<array{name: string, rows: list<list<string|null>>, widths: list<int>, formats: list<int>, bold_rows: list<int>}> */
    private array $sheets = [];

    /**
     * @param  list<list<string|null>>  $rows
     * @param  list<int>  $widths  column widths in characters
     * @param  list<int>  $formats  FORMAT_* per column (applies to the whole column, incl. empty cells)
     * @param  list<int>  $boldRows  0-based row indexes rendered bold
     */
    public function addSheet(string $name, array $rows, array $widths = [], array $formats = [], array $boldRows = [0]): self
    {
        $this->sheets[] = ['name' => $name, 'rows' => $rows, 'widths' => $widths, 'formats' => $formats, 'bold_rows' => $boldRows];

        return $this;
    }

    /** The workbook as a binary string. */
    public function toString(): string
    {
        if ($this->sheets === []) {
            throw new RuntimeException('A workbook needs at least one sheet.');
        }
        $path = tempnam(sys_get_temp_dir(), 'xlsx');
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create the workbook.');
        }
        // [Content_Types].xml first: file-type sniffers identify the zip as a spreadsheet by it.
        $zip->addFromString('[Content_Types].xml', $this->contentTypes());
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>');
        $zip->addFromString('xl/workbook.xml', $this->workbook());
        $zip->addFromString('xl/_rels/workbook.xml.rels', $this->workbookRels());
        $zip->addFromString('xl/styles.xml', $this->styles());
        foreach ($this->sheets as $i => $sheet) {
            $zip->addFromString('xl/worksheets/sheet'.($i + 1).'.xml', $this->sheet($sheet));
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    private function contentTypes(): string
    {
        $sheets = '';
        foreach (array_keys($this->sheets) as $i) {
            $sheets .= '<Override PartName="/xl/worksheets/sheet'.($i + 1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .$sheets.'</Types>';
    }

    private function workbook(): string
    {
        $sheets = '';
        foreach ($this->sheets as $i => $sheet) {
            $sheets .= '<sheet name="'.$this->esc($sheet['name']).'" sheetId="'.($i + 1).'" r:id="rId'.($i + 1).'"/>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'.$sheets.'</sheets></workbook>';
    }

    private function workbookRels(): string
    {
        $rels = '';
        foreach (array_keys($this->sheets) as $i) {
            $rels .= '<Relationship Id="rId'.($i + 1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.($i + 1).'.xml"/>';
        }
        $styles = count($this->sheets) + 1;

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'.$rels
            .'<Relationship Id="rId'.$styles.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
    }

    /** cellXfs: 0 general, 1 text (@), 2 bold, 3 date (yyyy-mm-dd). */
    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<numFmts count="1"><numFmt numFmtId="164" formatCode="yyyy-mm-dd"/></numFmts>'
            .'<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            .'<fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills>'
            .'<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
            .'<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
            .'<cellXfs count="4">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="49" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
            .'</cellXfs>'
            .'<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
            .'</styleSheet>';
    }

    private function sheet(array $sheet): string
    {
        $cols = '';
        $columnCount = max(count($sheet['widths']), count($sheet['formats']));
        for ($c = 0; $c < $columnCount; $c++) {
            $width = $sheet['widths'][$c] ?? 14;
            $format = $sheet['formats'][$c] ?? self::FORMAT_GENERAL;
            $cols .= '<col min="'.($c + 1).'" max="'.($c + 1).'" width="'.$width.'" customWidth="1"'.($format ? ' style="'.$format.'"' : '').'/>';
        }

        $rows = '';
        foreach ($sheet['rows'] as $r => $cells) {
            $bold = in_array($r, $sheet['bold_rows'], true);
            $xml = '';
            foreach ($cells as $c => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $style = $bold ? self::STYLE_BOLD : ($sheet['formats'][$c] ?? self::FORMAT_GENERAL);
                $xml .= '<c r="'.self::column($c).($r + 1).'" t="inlineStr"'.($style ? ' s="'.$style.'"' : '').'><is><t xml:space="preserve">'.$this->esc($value).'</t></is></c>';
            }
            $rows .= '<row r="'.($r + 1).'">'.$xml.'</row>';
        }

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .($cols !== '' ? '<cols>'.$cols.'</cols>' : '')
            .'<sheetData>'.$rows.'</sheetData></worksheet>';
    }

    /** 0 → A, 25 → Z, 26 → AA. */
    public static function column(int $index): string
    {
        $name = '';
        for ($n = $index + 1; $n > 0; $n = intdiv($n - 1, 26)) {
            $name = chr(65 + ($n - 1) % 26).$name;
        }

        return $name;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
