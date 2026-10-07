<?php

namespace App\Domain\Dashboard;

/**
 * The "how was this computed" block every affected widget returns next to its data (envelope key
 * `basis`), so a reader can tell, without opening the help text:
 *  - which date each part of the figure is dated by (date_basis: list of {key, code});
 *  - which kinds of records are counted (includes) and which are deliberately not (excludes);
 *  - how complete the underlying data is (completeness) — a measured state, never a guess:
 *      COMPLETE     every record in the population has what the metric needs;
 *      PARTIAL      some records lack it (excluded / recorded-only); the figure covers the rest;
 *      UNAVAILABLE  none has it — the value must be shown as unavailable, never as 0;
 *  - since when the underlying history exists (history_from), where history starts at a feature date.
 * All codes are i18n keys under dashboard.basis.*; nothing here is display text.
 */
final class DataBasis
{
    public const COMPLETE = 'COMPLETE';

    public const PARTIAL = 'PARTIAL';

    public const UNAVAILABLE = 'UNAVAILABLE';

    /**
     * @param  list<array{key: string, code: string}>  $dateBasis
     * @param  list<string>  $includes
     * @param  list<string>  $excludes
     * @param  array{status: string, total: int, valid: int, excluded: int, ongoing?: int, reasons: list<array{code: string, n: int}>}|null  $completeness
     * @return array<string, mixed>
     */
    public static function make(array $dateBasis, array $includes, array $excludes, ?array $completeness = null, ?string $historyFrom = null): array
    {
        return ['date_basis' => $dateBasis, 'includes' => $includes, 'excludes' => $excludes, 'completeness' => $completeness, 'history_from' => $historyFrom];
    }

    /**
     * Completeness of a population: $valid usable records out of $total; $reasons = why the rest is
     * excluded (code → count, zero counts dropped).
     *
     * @param  array<string, int>  $reasons
     * @return array{status: string, total: int, valid: int, excluded: int, ongoing: int, reasons: list<array{code: string, n: int}>}
     */
    public static function completeness(int $total, int $valid, array $reasons = [], int $ongoing = 0): array
    {
        $excluded = max(0, $total - $valid);
        $status = $excluded === 0 ? self::COMPLETE : ($valid === 0 ? self::UNAVAILABLE : self::PARTIAL);
        $list = [];
        foreach ($reasons as $code => $n) {
            if ($n > 0) {
                $list[] = ['code' => (string) $code, 'n' => (int) $n];
            }
        }

        return ['status' => $status, 'total' => $total, 'valid' => $valid, 'excluded' => $excluded, 'ongoing' => $ongoing, 'reasons' => $list];
    }
}
