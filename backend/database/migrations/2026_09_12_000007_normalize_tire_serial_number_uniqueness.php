<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * G-26: serial_number uniqueness was byte-exact — "ABC1", "abc1", and
 * " abc1 " were three distinct rows under the same tenant. This adds a
 * case/whitespace-normalized uniqueness guard at the database level
 * (a partial expression index, since a duplicate physical-tire identity
 * must never exist even if application-layer trimming is ever bypassed).
 * The raw serial_number column and its original casing are untouched —
 * this only changes what counts as a duplicate for the uniqueness check.
 *
 * Per the task's safety instructions, existing data is inspected first;
 * this migration refuses to activate the constraint over unresolved
 * duplicates rather than silently merging or deleting any row.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('tires')
            ->selectRaw('tenant_id, lower(trim(serial_number)) as normalized, count(*) as cnt')
            ->whereNull('deleted_at')
            ->groupBy('tenant_id', DB::raw('lower(trim(serial_number))'))
            ->havingRaw('count(*) > 1')
            ->get();

        if ($duplicates->isNotEmpty()) {
            $summary = $duplicates->map(fn ($d) => "tenant {$d->tenant_id}: '{$d->normalized}' x{$d->cnt}")->implode('; ');
            throw new RuntimeException(
                'Cannot activate normalized serial-number uniqueness: existing case/whitespace-variant '.
                "duplicates found ({$summary}). Resolve these tires (merge, correct, or archive) before ".
                're-running this migration — no row was modified or deleted automatically.'
            );
        }

        DB::statement(
            'CREATE UNIQUE INDEX tires_tenant_normalized_serial_unique ON tires '.
            '(tenant_id, lower(trim(serial_number))) WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS tires_tenant_normalized_serial_unique');
    }
};
