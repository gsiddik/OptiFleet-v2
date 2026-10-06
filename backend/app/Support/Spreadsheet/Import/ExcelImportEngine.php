<?php

namespace App\Support\Spreadsheet\Import;

use App\Domain\Shared\Support\Messages;
use App\Support\Spreadsheet\XlsxException;
use App\Support\Spreadsheet\XlsxReader;
use App\Support\Spreadsheet\XlsxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared Excel import infrastructure (Rim serials, Vehicles, Products):
 *
 *  - template(): a workbook with exactly two sheets, "How To" (instructions, the column list with
 *    Required / Optional, domain rules and examples) and "Fill Here" (the header row only);
 *  - preview(): reads only "Fill Here" (exact sheet name), checks the header row, reads every row,
 *    checks cell formats and unsafe content, then lets the definition classify the rows as VALID,
 *    DUPLICATE or INVALID. Nothing is saved;
 *  - import(): the rows the user selected are normalized and validated AGAIN (the preview is never
 *    trusted); every VALID row is persisted in its own savepoint, so an expected business rejection of
 *    one row never rolls back the others. Any other (technical) failure rolls back the whole import.
 *
 * Messages are rendered in the request locale; statuses stay canonical codes.
 */
class ExcelImportEngine
{
    public const SHEET_HOW_TO = 'How To';

    public const SHEET_FILL = 'Fill Here';

    public const MAX_ROWS = 1000;

    public const MAX_FILE_KB = 5120;

    public const VALID = 'VALID';

    public const DUPLICATE = 'DUPLICATE';

    public const INVALID = 'INVALID';

    /** Characters that make a spreadsheet application evaluate a text cell (formula injection). */
    private const UNSAFE_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    public function template(ExcelImportDefinition $definition, ?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $columns = $definition->columns();
        $l = fn (string $key, array $params = []) => Messages::text($key, $params, $locale);

        $howTo = [[$definition->title($locale)], ['']];
        $general = [
            $l('excelImport.howTo.fillSheet', ['sheet' => self::SHEET_FILL]),
            $l('excelImport.howTo.doNotRename', ['howTo' => self::SHEET_HOW_TO, 'fill' => self::SHEET_FILL]),
            $l('excelImport.howTo.requiredColumns'),
            $l('excelImport.howTo.formats'),
            $l('excelImport.howTo.noFormulas'),
            $l('excelImport.howTo.maxRows', ['maxRows' => self::MAX_ROWS]),
            $l('excelImport.howTo.upload'),
        ];
        foreach ($general as $i => $line) {
            $howTo[] = [($i + 1).'. '.$line];
        }
        $howTo[] = [''];
        $bold = [0];
        $bold[] = count($howTo);
        $howTo[] = [$l('excelImport.howTo.columnsTitle')];
        $bold[] = count($howTo);
        $howTo[] = [$l('excelImport.howTo.column'), $l('excelImport.howTo.requirement'), $l('excelImport.howTo.description')];
        foreach ($columns as $column) {
            $howTo[] = [
                $column->header($locale),
                $column->required ? $l('excelImport.howTo.required') : $l('excelImport.howTo.optional'),
                $column->helpKey ? Messages::text($column->helpKey, $column->helpParams, $locale) : '',
            ];
        }
        $rules = $definition->instructions($locale);
        if ($rules !== []) {
            $howTo[] = [''];
            $bold[] = count($howTo);
            $howTo[] = [$l('excelImport.howTo.rulesTitle')];
            foreach ($rules as $line) {
                $howTo[] = ['• '.$line];
            }
        }
        $howTo[] = [''];
        $bold[] = count($howTo);
        $howTo[] = [$l('excelImport.howTo.examplesTitle')];
        $bold[] = count($howTo);
        $howTo[] = array_map(fn (ImportColumn $c) => $c->header($locale), $columns);
        foreach ($definition->examples() as $example) {
            $howTo[] = array_map(fn (ImportColumn $c) => (string) ($example[$c->id] ?? ''), $columns);
        }

        $headers = array_map(fn (ImportColumn $c) => $c->header($locale), $columns);
        $widths = array_map(fn (ImportColumn $c) => $c->width, $columns);
        $formats = array_map(fn (ImportColumn $c) => $c->format === ImportColumn::DATE ? XlsxWriter::FORMAT_DATE : XlsxWriter::FORMAT_TEXT, $columns);

        return (new XlsxWriter)
            ->addSheet(self::SHEET_HOW_TO, $howTo, [max(36, $widths[0] ?? 0), 18, 70, ...array_slice($widths, 3)], [], $bold)
            ->addSheet(self::SHEET_FILL, [$headers], $widths, $formats)
            ->toString();
    }

    /**
     * @return array{columns: list<array{id: string, header: string, required: bool}>, rows: list<array>, summary: array}
     *
     * @throws ValidationException when the workbook or its structure is not the template's
     */
    public function preview(ExcelImportDefinition $definition, string $path): array
    {
        $rows = $this->classify($definition, $this->read($definition, $path));

        return ['columns' => $this->columnsPayload($definition), 'rows' => $rows, 'summary' => $this->summary($rows)];
    }

    /**
     * @param  list<array{row?: int|null, values?: array<string, mixed>}>  $selected
     * @return array{status: string, imported: int, created: list<array>, failed: list<array>}
     */
    public function import(ExcelImportDefinition $definition, array $selected): array
    {
        $rows = [];
        foreach (array_values($selected) as $i => $input) {
            $rows[] = $this->normalize($definition, (int) ($input['row'] ?? $i + 2), (array) ($input['values'] ?? []));
        }
        $rows = $this->classify($definition, $rows);

        $created = [];
        $failed = [];
        DB::transaction(function () use ($definition, $rows, &$created, &$failed) {
            foreach ($rows as $row) {
                if ($row['status'] !== self::VALID) {
                    $failed[] = $this->failure($row);

                    continue;
                }
                try {
                    $created[] = ['row' => $row['row']] + DB::transaction(fn () => $definition->persist($row));
                } catch (ValidationException $e) {
                    $failed[] = $this->failure(['status' => self::INVALID, 'errors' => array_values(array_unique(array_merge(...array_values($e->errors()))))] + $row);
                } catch (UniqueConstraintViolationException) {
                    $failed[] = $this->failure(['status' => self::DUPLICATE, 'errors' => [Messages::localized('excelImport.errors.duplicateOnSave')]] + $row);
                }
            }
        });

        return [
            'status' => $created === [] ? 'FAILED' : ($failed === [] ? 'SUCCESS' : 'PARTIAL'),
            'imported' => count($created),
            'created' => $created,
            'failed' => $failed,
        ];
    }

    /** @return list<array{id: string, header: string, required: bool}> */
    public function columnsPayload(ExcelImportDefinition $definition): array
    {
        return array_map(fn (ImportColumn $c) => ['id' => $c->id, 'header' => $c->header(app()->getLocale()), 'required' => $c->required], $definition->columns());
    }

    /** @return array{total: int, valid: int, duplicate: int, invalid: int} */
    public function summary(array $rows): array
    {
        $count = fn (string $status) => count(array_filter($rows, fn ($r) => $r['status'] === $status));

        return ['total' => count($rows), 'valid' => $count(self::VALID), 'duplicate' => $count(self::DUPLICATE), 'invalid' => $count(self::INVALID)];
    }

    /** Format-checked rows go to the definition; rows with format errors are INVALID already. */
    private function classify(ExcelImportDefinition $definition, array $rows): array
    {
        foreach ($rows as &$row) {
            $row['status'] = $row['errors'] === [] ? null : self::INVALID;
        }
        unset($row);
        $checked = $definition->validate($rows);
        foreach ($checked as &$row) {
            $row['status'] ??= $row['errors'] === [] ? self::VALID : self::INVALID;
            $row['errors'] = array_values(array_unique($row['errors']));
        }

        return $checked;
    }

    /** @return list<array> rows of "Fill Here" (structure checked, cells normalized) */
    private function read(ExcelImportDefinition $definition, string $path): array
    {
        $columns = $definition->columns();
        try {
            $reader = XlsxReader::open($path);
            if (! in_array(self::SHEET_FILL, array_map('trim', $reader->sheetNames()), true)) {
                throw ValidationException::withMessages(['file' => Messages::localized('excelImport.errors.sheetMissing', ['sheet' => self::SHEET_FILL])]);
            }
            $name = collect($reader->sheetNames())->first(fn ($n) => trim($n) === self::SHEET_FILL);
            $sheet = $reader->rows($name);
        } catch (XlsxException) {
            throw ValidationException::withMessages(['file' => Messages::localized('excelImport.errors.fileInvalid')]);
        }

        $headerRow = $sheet[1] ?? [];
        $width = max(count($columns), $headerRow === [] ? 0 : max(array_keys($headerRow)) + 1);
        $headersOk = $width === count($columns);
        foreach ($columns as $i => $column) {
            if (! in_array(mb_strtolower(trim((string) ($headerRow[$i]['value'] ?? ''))), $column->acceptedHeaders(), true)) {
                $headersOk = false;
            }
        }
        if (! $headersOk) {
            throw ValidationException::withMessages(['file' => Messages::localized('excelImport.errors.headerMismatch', [
                'sheet' => self::SHEET_FILL,
                'headers' => implode(', ', array_map(fn (ImportColumn $c) => $c->header(app()->getLocale()), $columns)),
            ])]);
        }
        unset($sheet[1]);
        if ($sheet === []) {
            throw ValidationException::withMessages(['file' => Messages::localized('excelImport.errors.noRows', ['sheet' => self::SHEET_FILL])]);
        }
        if (count($sheet) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => Messages::localized('excelImport.errors.maxRows', ['maxRows' => self::MAX_ROWS])]);
        }

        $rows = [];
        foreach ($sheet as $number => $cells) {
            $values = [];
            foreach ($columns as $i => $column) {
                $values[$column->id] = $cells[$i] ?? null;
            }
            $row = $this->normalize($definition, $number, $values);
            if (array_diff(array_keys($cells), array_keys($columns)) !== []) {
                $row['errors'][] = Messages::localized('excelImport.errors.extraValues');
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * One row → normalized values + format errors. A value is a reader cell (['type', 'value',
     * 'formula'?]) from the workbook or a plain value sent back from the preview.
     *
     * @param  array<string, mixed>  $raw  by column id
     */
    public function normalize(ExcelImportDefinition $definition, int $number, array $raw): array
    {
        $values = [];
        $errors = [];
        foreach ($definition->columns() as $column) {
            [$value, $error] = $this->cell($column, $raw[$column->id] ?? null);
            $values[$column->id] = $value;
            if ($error !== null) {
                $errors[] = $error;
            }
        }

        return ['row' => $number, 'values' => $values, 'errors' => $errors, 'status' => null];
    }

    /** @return array{0: mixed, 1: ?string} [normalized value (or the text as entered), error] */
    private function cell(ImportColumn $column, mixed $cell): array
    {
        $name = $column->header(app()->getLocale());
        $isCell = is_array($cell);
        if ($isCell && ! empty($cell['formula'])) {
            return [null, Messages::localized('excelImport.errors.formulaNotAllowed', ['column' => $name])];
        }
        $raw = $isCell ? ($cell['value'] ?? '') : $cell;
        if (is_bool($raw)) {
            $text = $raw ? 'true' : 'false';
        } else {
            $text = is_scalar($raw) ? trim((string) $raw) : '';
        }
        if ($text === '') {
            return [null, $column->required ? Messages::localized('excelImport.errors.required', ['column' => $name]) : null];
        }

        switch ($column->format) {
            case ImportColumn::DATE:
                if ($isCell && ($cell['type'] ?? null) === 'n' && is_numeric($text) && (float) $text >= 1 && (float) $text < 2958466) {
                    return [CarbonImmutable::create(1899, 12, 30)->addDays((int) floor((float) $text))->toDateString(), null];
                }
                if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                    return [$text, null];
                }

                return [$text, Messages::localized('excelImport.errors.invalidDate', ['column' => $name])];
            case ImportColumn::NUMBER:
                return preg_match('/^-?\d+(\.\d+)?$/', $text) ? [$text, null] : [$text, Messages::localized('excelImport.errors.invalidNumber', ['column' => $name])];
            case ImportColumn::INTEGER:
                if (preg_match('/^-?\d+(\.0+)?$/', $text)) {
                    return [(string) (int) $text, null];
                }

                return [$text, Messages::localized('excelImport.errors.invalidInteger', ['column' => $name])];
            case ImportColumn::BOOLEAN:
                $lower = mb_strtolower($text);
                if (in_array($lower, ['yes', 'ya', 'true', '1', 'y'], true)) {
                    return [true, null];
                }
                if (in_array($lower, ['no', 'tidak', 'false', '0', 'n'], true)) {
                    return [false, null];
                }

                return [$text, Messages::localized('excelImport.errors.invalidBoolean', ['column' => $name])];
            default:
                if (in_array(mb_substr($text, 0, 1), self::UNSAFE_PREFIXES, true)) {
                    return [$text, Messages::localized('excelImport.errors.unsafeText', ['column' => $name])];
                }
                if (mb_strlen($text) > $column->maxLength) {
                    return [$text, Messages::localized('excelImport.errors.tooLong', ['column' => $name, 'max' => $column->maxLength])];
                }

                return [$text, null];
        }
    }

    private function failure(array $row): array
    {
        return ['row' => $row['row'], 'values' => $row['values'], 'status' => $row['status'], 'reason' => implode(' ', $row['errors'])];
    }
}
