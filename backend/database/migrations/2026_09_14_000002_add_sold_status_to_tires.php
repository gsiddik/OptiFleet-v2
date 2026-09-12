<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase F / BD-5: SOLD is a new terminal tires.current_status for
 * TireService::sell() — distinct from SCRAPPED (destroyed) and REMOVED
 * (still fleet property, just off a vehicle). Like every other non-
 * IN_STOCK/RESERVED status, it is excluded from install()'s accepted
 * statuses, so a sold tire can never be installed through any endpoint.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE tires DROP CONSTRAINT tires_current_status_check');
        DB::statement(
            'ALTER TABLE tires ADD CONSTRAINT tires_current_status_check '.
            'CHECK (current_status::text = ANY (ARRAY['.
            "'IN_STOCK','RESERVED','INSTALLED','IN_USE','REMOVED','UNDER_INSPECTION',".
            "'RETREAD','REPAIR','QUARANTINED','SCRAPPED','SOLD','LOST'".
            ']::character varying[]))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tires DROP CONSTRAINT tires_current_status_check');
        DB::statement(
            'ALTER TABLE tires ADD CONSTRAINT tires_current_status_check '.
            'CHECK (current_status::text = ANY (ARRAY['.
            "'IN_STOCK','RESERVED','INSTALLED','IN_USE','REMOVED','UNDER_INSPECTION',".
            "'RETREAD','REPAIR','QUARANTINED','SCRAPPED','LOST'".
            ']::character varying[]))'
        );
    }
};
