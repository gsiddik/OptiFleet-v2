<?php

namespace App\Domain\Invoice\Services;

use Illuminate\Support\Facades\DB;

/**
 * Concurrency-safe sequential document numbering shared by invoices and
 * contracts. Must always be called from inside the caller's own DB
 * transaction so the row lock is held for the duration of the surrounding
 * write (see InvoiceNumberService / ContractNumberService usage).
 */
class NumberSequenceService
{
    public function next(string $type, ?int $year = null): int
    {
        $year ??= (int) now()->format('Y');
        $key = "{$type}:{$year}";

        DB::statement(
            'INSERT INTO commercial_number_sequences (sequence_key, last_number) VALUES (?, 0) ON CONFLICT (sequence_key) DO NOTHING',
            [$key]
        );

        $row = DB::table('commercial_number_sequences')
            ->where('sequence_key', $key)
            ->lockForUpdate()
            ->first();

        $next = $row->last_number + 1;

        DB::table('commercial_number_sequences')
            ->where('sequence_key', $key)
            ->update(['last_number' => $next]);

        return $next;
    }
}
