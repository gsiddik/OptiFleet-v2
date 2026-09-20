<?php

namespace App\Domain\WorkOrder\Services;

use App\Domain\Invoice\Services\NumberSequenceService;

/**
 * Work Authorization Letter numbering. The source document specifies
 * `WAL/[Bulan Romawi]/[tanggal issue][3 digit sequence, reset per bulan]`
 * with no explicit separator between the date and the sequence — read
 * literally that concatenation is ambiguous (e.g. is "202609190011" day 19
 * seq 001, or day 190 seq 011?). Per instruction, this is flagged as a
 * PROVISIONAL format pending business confirmation rather than guessed
 * into something that could misread: an explicit hyphen separates the
 * date from the sequence. Changing the separator/format later never
 * affects an already-issued number, since every WAL snapshots its own
 * number at generation time.
 *
 * The sequence itself reuses the same atomic, row-locked primitive that
 * already powers invoice/contract numbering (NumberSequenceService) —
 * keyed per tenant + calendar month, so it resets every month without a
 * dedicated sequence table.
 */
class WalNumberGenerator
{
    private const ROMAN_MONTHS = [
        1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI',
        7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII',
    ];

    public function __construct(private readonly NumberSequenceService $sequences) {}

    /** Must be called from inside the caller's own DB transaction (same contract as NumberSequenceService::next()). */
    public function generate(string $tenantId, \DateTimeInterface $issueDate): string
    {
        $key = "wal:{$tenantId}:{$issueDate->format('Ym')}";
        $seq = $this->sequences->next($key, 0, 1);

        $roman = self::ROMAN_MONTHS[(int) $issueDate->format('n')];

        return sprintf('WAL/%s/%s-%03d', $roman, $issueDate->format('Ymd'), $seq);
    }
}
