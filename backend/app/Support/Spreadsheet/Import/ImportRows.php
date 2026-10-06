<?php

namespace App\Support\Spreadsheet\Import;

use App\Domain\Shared\Support\Messages;
use App\Domain\Shared\Support\ResponseMessageLocalizer;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Row helpers shared by the import definitions (status rules and localized row messages). */
final class ImportRows
{
    /** Marks a row INVALID with a message (a DUPLICATE row stays DUPLICATE). */
    public static function invalid(array &$row, string $message): void
    {
        $row['errors'][] = $message;
        if (($row['status'] ?? null) !== ExcelImportEngine::DUPLICATE) {
            $row['status'] = ExcelImportEngine::INVALID;
        }
    }

    public static function duplicate(array &$row, string $message): void
    {
        $row['errors'][] = $message;
        $row['status'] = ExcelImportEngine::DUPLICATE;
    }

    /** True while no error has been found for the row (format or business). */
    public static function open(array $row): bool
    {
        return ($row['status'] ?? null) === null && $row['errors'] === [];
    }

    /**
     * Marks the 2nd+ rows whose key repeats an earlier still-valid row as DUPLICATE (case-insensitive).
     *
     * @param  callable(array): ?string  $key
     */
    public static function repeatedInFile(array &$rows, callable $key, string $columnLabel): void
    {
        $seen = [];
        foreach ($rows as &$row) {
            if (! self::open($row)) {
                continue;
            }
            $value = $key($row);
            if ($value === null || $value === '') {
                continue;
            }
            $k = Str::lower(trim($value));
            if (isset($seen[$k])) {
                self::duplicate($row, Messages::localized('excelImport.errors.repeatedInFile', ['column' => $columnLabel, 'value' => $value, 'row' => $seen[$k]]));
            } else {
                $seen[$k] = $row['row'];
            }
        }
    }

    /**
     * Flattened messages of a ValidationException (for a row reason), in the request locale: domain
     * services throw dataset English, which is translated the same way as API `errors`.
     */
    public static function messages(ValidationException $e): array
    {
        $locale = app()->getLocale();

        return array_values(array_unique(array_map(
            fn (string $m) => ResponseMessageLocalizer::localize($m, $locale),
            array_merge(...array_values($e->errors())),
        )));
    }
}
