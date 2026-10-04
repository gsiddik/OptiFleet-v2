<?php

namespace App\Support\Spreadsheet;

use SimpleXMLElement;
use ZipArchive;

/**
 * Minimal, defensive .xlsx reader: sheet names and the cell values of one sheet. Supports shared
 * strings (incl. rich text runs), inline strings, formula string results, numbers and booleans —
 * what Excel, LibreOffice and Google Sheets write. Rejects anything that is not a well-formed
 * workbook, DOCTYPE declarations (entity expansion) and oversized parts (zip bombs).
 * Unprefixed attributes are read through attributes(): after children($ns) SimpleXML would
 * otherwise look them up in the element namespace and find nothing.
 */
class XlsxReader
{
    private const MAIN_NS = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

    private const REL_NS = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    private const PKG_REL_NS = 'http://schemas.openxmlformats.org/package/2006/relationships';

    /** Largest uncompressed part we read (bytes). */
    private const MAX_PART_BYTES = 20 * 1024 * 1024;

    private ZipArchive $zip;

    /** @var array<string, string> sheet name → part path inside the zip */
    private array $sheetPaths = [];

    /** @var list<string>|null */
    private ?array $sharedStrings = null;

    private function __construct() {}

    /** @throws XlsxException when the file is not a readable .xlsx workbook */
    public static function open(string $path): self
    {
        $reader = new self;
        $reader->zip = new ZipArchive;
        if ($reader->zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new XlsxException('The file is not a valid Excel (.xlsx) workbook.');
        }
        $workbook = $reader->xml('xl/workbook.xml');
        $rels = $reader->xml('xl/_rels/workbook.xml.rels');
        $targets = [];
        foreach ($rels->children(self::PKG_REL_NS)->Relationship ?? [] as $rel) {
            $target = (string) $rel->attributes()['Target'];
            $targets[(string) $rel->attributes()['Id']] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
        }
        foreach ($workbook->children(self::MAIN_NS)->sheets->sheet ?? [] as $sheet) {
            $id = (string) $sheet->attributes(self::REL_NS)['id'];
            if (isset($targets[$id])) {
                $reader->sheetPaths[(string) $sheet->attributes()['name']] = $targets[$id];
            }
        }
        if ($reader->sheetPaths === []) {
            throw new XlsxException('The file is not a valid Excel (.xlsx) workbook.');
        }

        return $reader;
    }

    /** @return list<string> */
    public function sheetNames(): array
    {
        return array_keys($this->sheetPaths);
    }

    /**
     * Rows of one sheet, in order, keyed by their 1-based row number; each row maps a 0-based
     * column index to ['type' => 'n'|'s'|'b', 'value' => string]. Empty cells are omitted.
     *
     * @return array<int, array<int, array{type: string, value: string}>>
     */
    public function rows(string $sheetName): array
    {
        $sheet = $this->xml($this->sheetPaths[$sheetName] ?? throw new XlsxException("Sheet \"{$sheetName}\" not found."));
        $out = [];
        $nextRow = 1;
        foreach ($sheet->children(self::MAIN_NS)->sheetData->row ?? [] as $row) {
            $number = isset($row->attributes()['r']) ? (int) $row->attributes()['r'] : $nextRow;
            $nextRow = $number + 1;
            $cells = [];
            $nextColumn = 0;
            foreach ($row->children(self::MAIN_NS)->c as $cell) {
                $column = isset($cell->attributes()['r']) ? self::columnIndex((string) $cell->attributes()['r']) : $nextColumn;
                $nextColumn = $column + 1;
                $value = $this->cellValue($cell);
                if ($value !== null && $value['value'] !== '') {
                    $cells[$column] = $value;
                }
            }
            if ($cells !== []) {
                $out[$number] = $cells;
            }
        }

        return $out;
    }

    /** @return array{type: string, value: string}|null */
    private function cellValue(SimpleXMLElement $cell): ?array
    {
        $type = (string) ($cell->attributes()['t'] ?? 'n');
        $children = $cell->children(self::MAIN_NS);

        return match ($type) {
            's' => ['type' => 's', 'value' => $this->sharedStrings()[(int) $children->v] ?? ''],
            'inlineStr' => ['type' => 's', 'value' => $this->text($children->is)],
            'str' => ['type' => 's', 'value' => (string) $children->v],
            'b' => ['type' => 'b', 'value' => (string) $children->v],
            'e' => null,
            default => isset($children->v) ? ['type' => 'n', 'value' => (string) $children->v] : null,
        };
    }

    /** @return list<string> */
    private function sharedStrings(): array
    {
        if ($this->sharedStrings === null) {
            $this->sharedStrings = [];
            if ($this->zip->locateName('xl/sharedStrings.xml') !== false) {
                foreach ($this->xml('xl/sharedStrings.xml')->children(self::MAIN_NS)->si as $si) {
                    $this->sharedStrings[] = $this->text($si);
                }
            }
        }

        return $this->sharedStrings;
    }

    /** Text of an <si> / <is>: a plain <t>, or the concatenated <r><t> runs of rich text. */
    private function text(?SimpleXMLElement $node): string
    {
        if ($node === null) {
            return '';
        }
        $children = $node->children(self::MAIN_NS);
        if (isset($children->t)) {
            return (string) $children->t;
        }
        $text = '';
        foreach ($children->r as $run) {
            $text .= (string) $run->children(self::MAIN_NS)->t;
        }

        return $text;
    }

    private function xml(string $part): SimpleXMLElement
    {
        $stat = $this->zip->statName($part);
        if ($stat === false || $stat['size'] > self::MAX_PART_BYTES) {
            throw new XlsxException('The file is not a valid Excel (.xlsx) workbook.');
        }
        $content = $this->zip->getFromName($part);
        if ($content === false || stripos($content, '<!DOCTYPE') !== false) {
            throw new XlsxException('The file is not a valid Excel (.xlsx) workbook.');
        }
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if ($xml === false) {
            throw new XlsxException('The file is not a valid Excel (.xlsx) workbook.');
        }

        return $xml;
    }

    /** "C12" → 2. */
    public static function columnIndex(string $reference): int
    {
        $letters = strtoupper((string) preg_replace('/[^A-Za-z]/', '', $reference));
        $index = 0;
        foreach (str_split($letters) as $letter) {
            $index = $index * 26 + (ord($letter) - 64);
        }

        return max($index - 1, 0);
    }
}
