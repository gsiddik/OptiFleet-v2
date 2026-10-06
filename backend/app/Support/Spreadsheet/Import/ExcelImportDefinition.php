<?php

namespace App\Support\Spreadsheet\Import;

/**
 * The domain side of one Excel import (Rim serials, Vehicles, Products …). The shared ExcelImportEngine
 * owns the workbook (template, sheet / header checks, cell formats, security checks, preview, row
 * selection and per-row savepoints); a definition owns only its columns and its business rules, which
 * must be the same rules as the module's normal create path.
 */
interface ExcelImportDefinition
{
    /** @return list<ImportColumn> the "Fill Here" columns, in order */
    public function columns(): array;

    /** Title line of the How To sheet. */
    public function title(string $locale): string;

    /** @return list<string> domain instructions for the How To sheet (rules, references …) */
    public function instructions(string $locale): array;

    /** @return list<array<string, string>> example rows keyed by column id */
    public function examples(): array;

    /**
     * Business validation of rows whose cell formats are valid (status null). Sets 'status' to VALID,
     * DUPLICATE or INVALID and appends localized messages to 'errors'. Must check duplicates against the
     * database and within the given rows.
     *
     * @param  list<array{row: int, values: array<string, mixed>, errors: list<string>, status: ?string}>  $rows
     * @return list<array>
     */
    public function validate(array $rows): array;

    /**
     * Persists one VALID row through the module's normal create path. Throw a ValidationException for a
     * business rejection (the row is reported, the other rows are kept).
     *
     * @return array{id: string, label: string}
     */
    public function persist(array $row): array;
}
