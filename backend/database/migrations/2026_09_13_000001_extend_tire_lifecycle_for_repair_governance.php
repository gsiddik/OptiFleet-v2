<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Phase E (G-27): REPAIR is a distinct disposition/lifecycle outcome from
 * RETREAD — a tire removed for repair must not be indistinguishable from
 * one removed for retreading. QUARANTINED is the terminal state for a
 * tire whose final inspection after repair/retread finds it unsafe but
 * not scrap-worthy (e.g. pending a policy/legal decision) — it is
 * deliberately excluded from every status TireService::install() accepts
 * (IN_STOCK/RESERVED only), so a quarantined tire cannot be installed
 * through any endpoint without first being explicitly re-approved.
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
            "'RETREAD','REPAIR','QUARANTINED','SCRAPPED','LOST'".
            ']::character varying[]))'
        );

        DB::statement('ALTER TABLE tire_removals DROP CONSTRAINT tire_removals_disposition_check');
        DB::statement(
            'ALTER TABLE tire_removals ADD CONSTRAINT tire_removals_disposition_check '.
            "CHECK (disposition::text = ANY (ARRAY['REUSE','RETREAD','REPAIR','SCRAP']::character varying[]))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tire_removals DROP CONSTRAINT tire_removals_disposition_check');
        DB::statement(
            'ALTER TABLE tire_removals ADD CONSTRAINT tire_removals_disposition_check '.
            "CHECK (disposition::text = ANY (ARRAY['REUSE','RETREAD','SCRAP']::character varying[]))"
        );

        DB::statement('ALTER TABLE tires DROP CONSTRAINT tires_current_status_check');
        DB::statement(
            'ALTER TABLE tires ADD CONSTRAINT tires_current_status_check '.
            'CHECK (current_status::text = ANY (ARRAY['.
            "'IN_STOCK','RESERVED','INSTALLED','IN_USE','REMOVED','UNDER_INSPECTION',".
            "'RETREAD','SCRAPPED','LOST'".
            ']::character varying[]))'
        );
    }
};
