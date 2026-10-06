<?php

namespace App\Domain\Tire\Services;

use App\Domain\ProductMaster\Models\Product;
use App\Domain\Shared\Support\Messages;
use App\Domain\Tire\Models\Tire;
use App\Support\Spreadsheet\XlsxException;
use App\Support\Spreadsheet\XlsxReader;
use App\Support\Spreadsheet\XlsxWriter;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * New Stock import of physical tires for one Tire Product from an Excel workbook:
 *
 *  - template(): a workbook with a "How To" sheet (instructions + examples) and an empty
 *    "Fill Here" sheet whose header row is exactly HEADERS;
 *  - preview(): reads "Fill Here", checks the structure, and classifies every row as VALID,
 *    DUPLICATE (serial already registered for the tenant, or repeated in the file) or INVALID;
 *  - import(): registers the rows the user selected. Every row is validated again here — the
 *    preview is never trusted — and each tire is created through TireRegistrationService, the
 *    same path as Register Tire (IN_STOCK, specification taken from the product).
 *
 * Serial uniqueness is the tenant rule already enforced by the database
 * (tires_tenant_normalized_serial_unique: lower(trim(serial)) per tenant, excluding deleted tires).
 */
class TireImportService
{
    public const SHEET_HOW_TO = 'How To';

    public const SHEET_FILL = 'Fill Here';

    public const HEADERS = ['Serial Number', 'Manufacture Date Code', 'Purchase Date'];

    public const MAX_ROWS = 1000;

    public const VALID = 'VALID';

    public const DUPLICATE = 'DUPLICATE';

    public const INVALID = 'INVALID';

    public function __construct(private readonly TireRegistrationService $registration) {}

    public function template(Product $product): string
    {
        $examples = [
            ['A7KD-93LM-Q2XP-48TZR', '2326', '2026-07-01'], ['B4NF-71RC-W8EH-25MKD', '2326', '2026-07-01'],
            ['C9TP-36VG-K5UB-19XLA', '2226', '2026-06-24'], ['D2HW-58QS-M3ZN-74FJE', '2226', '2026-06-24'],
            ['E6LM-24BT-R9CX-63PQH', '2126', ''], ['F3XJ-87NE-H4WA-52GVK', '', '2026-06-10'],
            ['G8RZ-15KF-T6PD-39MCN', '1926', '2026-05-28'], ['H5BQ-62WX-J7LS-81TER', '1926', '2026-05-28'],
            ['J1UV-49DH-N2GM-67ZKP', '1826', '2026-05-15'], ['K7ME-33PA-X8QT-26HWB', '1826', '2026-05-15'],
            ['L4GC-95ZR-B1KV-58NDF', '', ''], ['M2SN-76HJ-E5XW-13QAT', '1726', '2026-05-02'],
        ];
        $howTo = [
            [$product->code ? Messages::text('documents.tireImport.howToTitleWithCode', ['productName' => $product->name, 'productCode' => $product->code]) : Messages::text('documents.tireImport.howToTitle', ['productName' => $product->name])],
            [''],
            ['1. Fill the sheet "Fill Here": one tire per row, starting on row 2. Do not rename the sheets or change the header row.'],
            ['2. Serial Number (required): the serial printed on the tire, at most 100 characters. It must not be registered yet for your company — letter case and surrounding spaces are ignored when comparing.'],
            ['3. Manufacture Date Code (optional): the DOT week/year code, at most 20 characters — e.g. 2326 = week 23 of 2026.'],
            ['4. Purchase Date (optional): a date, written as YYYY-MM-DD (e.g. 2026-07-01).'],
            ['5. The tire specification (size, pattern, load index…) comes from this product; every imported tire is registered as New Stock.'],
            ['6. Save the file as .xlsx, then in Tire Detail → New Stock → Import, upload it, review the preview, select the rows to import and confirm.'],
            [Messages::text('documents.tireImport.maxRowsNote', ['maxRows' => self::MAX_ROWS])],
            [''],
            ['Examples'],
            self::HEADERS,
            ...$examples,
        ];

        return (new XlsxWriter)
            ->addSheet(self::SHEET_HOW_TO, $howTo, [28, 24, 16], [], [0, 10, 11])
            ->addSheet(self::SHEET_FILL, [self::HEADERS], [28, 24, 16], [XlsxWriter::FORMAT_TEXT, XlsxWriter::FORMAT_TEXT, XlsxWriter::FORMAT_DATE])
            ->toString();
    }

    /**
     * @return array{rows: list<array>, summary: array{total: int, valid: int, duplicate: int, invalid: int}}
     *
     * @throws ValidationException when the workbook or its structure is not the template's
     */
    public function preview(string $tenantId, string $path): array
    {
        $rows = $this->classify($tenantId, $this->readFillSheet($path));

        return ['rows' => $rows, 'summary' => $this->summary($rows)];
    }

    /**
     * Registers the selected rows. All rows invalid/duplicate → nothing is saved.
     *
     * @param  list<array{row?: int|null, serial_number?: mixed, manufacture_date_code?: mixed, purchase_date?: mixed}>  $selected
     * @return array{status: string, imported: int, created: list<array>, failed: list<array>}
     */
    public function import(string $tenantId, Product $product, array $selected): array
    {
        $rows = [];
        foreach (array_values($selected) as $i => $input) {
            $rows[] = $this->normalize((int) ($input['row'] ?? $i + 2), $input['serial_number'] ?? null, $input['manufacture_date_code'] ?? null, $input['purchase_date'] ?? null);
        }
        $rows = $this->classify($tenantId, $rows);

        $created = [];
        $failed = [];
        DB::transaction(function () use ($rows, $tenantId, $product, &$created, &$failed) {
            foreach ($rows as $row) {
                if ($row['status'] !== self::VALID) {
                    $failed[] = $this->failure($row);

                    continue;
                }
                try {
                    // Savepoint per tire: a concurrent registration of the same serial fails this
                    // row only (the database unique index is the final guard).
                    $tire = DB::transaction(fn () => $this->registration->register($tenantId, [
                        'product_id' => $product->id,
                        'serial_number' => $row['serial_number'],
                        'manufacture_date_code' => $row['manufacture_date_code'],
                        'purchase_date' => $row['purchase_date'],
                    ]));
                    $created[] = ['row' => $row['row'], 'id' => $tire->id, 'serial_number' => $tire->serial_number];
                } catch (UniqueConstraintViolationException) {
                    $failed[] = $this->failure($row + ['status' => self::DUPLICATE], ['This serial number is already registered.']);
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

    /** @return list<array> normalized rows of "Fill Here" (structure checked) */
    private function readFillSheet(string $path): array
    {
        try {
            $reader = XlsxReader::open($path);
            if (! in_array(self::SHEET_FILL, $reader->sheetNames(), true)) {
                throw ValidationException::withMessages(['file' => Messages::text('validation.tire.importSheetMissing', ['sheet' => self::SHEET_FILL])]);
            }
            $sheet = $reader->rows(self::SHEET_FILL);
        } catch (XlsxException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        $headerRow = $sheet[1] ?? [];
        $headers = [];
        for ($c = 0; $c < max(count(self::HEADERS), $headerRow === [] ? 0 : max(array_keys($headerRow)) + 1); $c++) {
            $headers[] = trim($headerRow[$c]['value'] ?? '');
        }
        if ($headers !== self::HEADERS) {
            throw ValidationException::withMessages(['file' => Messages::text('validation.tire.importHeaderMismatch', ['sheet' => self::SHEET_FILL, 'headers' => implode(', ', self::HEADERS)])]);
        }
        unset($sheet[1]);
        if ($sheet === []) {
            throw ValidationException::withMessages(['file' => Messages::text('validation.tire.importNoRows', ['sheet' => self::SHEET_FILL])]);
        }
        if (count($sheet) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => Messages::text('validation.tire.importMaxRows', ['maxRows' => self::MAX_ROWS])]);
        }

        $rows = [];
        foreach ($sheet as $number => $cells) {
            $extra = array_diff(array_keys($cells), [0, 1, 2]);
            $row = $this->normalize($number, $cells[0] ?? null, $cells[1] ?? null, $cells[2] ?? null);
            if ($extra !== []) {
                $row['errors'][] = 'Values outside the three template columns are not allowed.';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * One row → trimmed values + format errors. Cells are reader cells (['type','value']) from the
     * workbook or plain values from the import request.
     */
    private function normalize(int $number, mixed $serial, mixed $code, mixed $date): array
    {
        $errors = [];
        $serial = $this->cellText($serial);
        $code = $this->cellText($code);
        if ($serial === '') {
            $errors[] = 'Serial Number is required.';
        } elseif (mb_strlen($serial) > 100) {
            $errors[] = 'Serial Number may not be longer than 100 characters.';
        }
        if (mb_strlen($code) > 20) {
            $errors[] = 'Manufacture Date Code may not be longer than 20 characters.';
        }
        [$purchaseDate, $dateError] = $this->purchaseDate($date);
        if ($dateError) {
            $errors[] = $dateError;
        }

        return [
            'row' => $number,
            'serial_number' => $serial,
            'manufacture_date_code' => $code === '' ? null : $code,
            'purchase_date' => $purchaseDate,
            'errors' => $errors,
        ];
    }

    private function cellText(mixed $cell): string
    {
        $value = is_array($cell) ? ($cell['value'] ?? '') : $cell;

        return is_scalar($value) ? trim((string) $value) : '';
    }

    /** @return array{0: ?string, 1: ?string} [Y-m-d (or the unparseable text), error] */
    private function purchaseDate(mixed $cell): array
    {
        $text = $this->cellText($cell);
        if ($text === '') {
            return [null, null];
        }
        // A real Excel date cell holds the day serial (1900 date system).
        if (is_array($cell) && ($cell['type'] ?? null) === 'n' && is_numeric($text) && (float) $text >= 1 && (float) $text < 2958466) {
            return [CarbonImmutable::create(1899, 12, 30)->addDays((int) floor((float) $text))->toDateString(), null];
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $text, $m) && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return [$text, null];
        }

        // The original text is kept, so importing this row from the preview is rejected again.
        return [$text, 'Purchase Date must be a date in YYYY-MM-DD format.'];
    }

    /** Adds status (VALID / DUPLICATE / INVALID) — duplicates against the tenant's tires and within the rows. */
    private function classify(string $tenantId, array $rows): array
    {
        $keys = array_values(array_unique(array_filter(array_map(fn ($r) => Str::lower($r['serial_number']), $rows))));
        $existing = [];
        foreach (array_chunk($keys, 500) as $chunk) {
            Tire::query()->where('tenant_id', $tenantId)
                ->whereIn(DB::raw('lower(trim(serial_number))'), $chunk)
                ->pluck('serial_number')
                ->each(function ($s) use (&$existing) {
                    $existing[Str::lower(trim($s))] = true;
                });
        }

        $seen = [];
        foreach ($rows as &$row) {
            $key = Str::lower($row['serial_number']);
            if ($row['errors'] !== []) {
                $row['status'] = self::INVALID;
            } elseif (isset($existing[$key])) {
                $row['status'] = self::DUPLICATE;
                $row['errors'][] = 'This serial number is already registered.';
            } elseif (isset($seen[$key])) {
                $row['status'] = self::DUPLICATE;
                $row['errors'][] = Messages::text('validation.tire.importSerialRepeated', ['row' => $seen[$key]]);
            } else {
                $row['status'] = self::VALID;
            }
            if ($row['status'] === self::VALID) {
                $seen[$key] = $row['row'];
            }
        }

        return $rows;
    }

    private function summary(array $rows): array
    {
        $count = fn (string $status) => count(array_filter($rows, fn ($r) => $r['status'] === $status));

        return ['total' => count($rows), 'valid' => $count(self::VALID), 'duplicate' => $count(self::DUPLICATE), 'invalid' => $count(self::INVALID)];
    }

    private function failure(array $row, ?array $errors = null): array
    {
        return [
            'row' => $row['row'],
            'serial_number' => $row['serial_number'],
            'manufacture_date_code' => $row['manufacture_date_code'],
            'purchase_date' => $row['purchase_date'],
            'status' => $row['status'],
            'reason' => implode(' ', $errors ?? $row['errors']),
        ];
    }
}
