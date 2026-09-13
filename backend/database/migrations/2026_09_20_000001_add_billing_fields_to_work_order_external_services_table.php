<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R1 (Workshop Invoice and Settlement): the authoritative business process
 * supplied for this work is that a Workshop Invoice is issued EXTERNALLY by
 * the Workshop Partner, never by an OptiFleet user — OptiFleet records it.
 * The Maintenance Memo (this table) is the OptiFleet-side document that
 * travels to the partner and back; its lifecycle now extends past
 * COMPLETED into BILLED (an external invoice has been recorded against it)
 * and PAID (payment evidence has been uploaded against that invoice).
 *
 * Also adds the remaining Maintenance Memo fields named in the business
 * requirements that did not already exist: a real document number (via the
 * same DocumentNumberingService/NUMBERING configuration every other
 * OptiFleet document uses — see ConfigurationDefaultsSeeder), diagnosis,
 * and requested parts/services (free text, matching the shallow-field
 * convention used throughout this codebase rather than inventing a
 * structured parts-request relation the business requirement never asked
 * for — `description` already exists for "requested repair or maintenance
 * work"; `diagnosis` is added as the distinct field the requirement lists
 * separately).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_external_services', function (Blueprint $table) {
            $table->string('memo_number')->nullable()->after('id');
            $table->uuid('numbering_configuration_version_id')->nullable()->after('memo_number');
            $table->text('diagnosis')->nullable()->after('description');
            $table->text('requested_parts_services')->nullable()->after('diagnosis');
        });

        DB::statement('ALTER TABLE work_order_external_services DROP CONSTRAINT work_order_external_services_status_check');
        DB::statement(
            'ALTER TABLE work_order_external_services ADD CONSTRAINT work_order_external_services_status_check '.
            "CHECK (status::text = ANY (ARRAY['REQUESTED','COMPLETED','CANCELLED','BILLED','PAID']::character varying[]))"
        );

        Schema::table('work_order_external_services', function (Blueprint $table) {
            $table->unique(['tenant_id', 'memo_number']);
        });
    }

    public function down(): void
    {
        Schema::table('work_order_external_services', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'memo_number']);
        });

        DB::statement('ALTER TABLE work_order_external_services DROP CONSTRAINT work_order_external_services_status_check');
        DB::statement(
            'ALTER TABLE work_order_external_services ADD CONSTRAINT work_order_external_services_status_check '.
            "CHECK (status::text = ANY (ARRAY['REQUESTED','COMPLETED','CANCELLED']::character varying[]))"
        );

        Schema::table('work_order_external_services', function (Blueprint $table) {
            $table->dropColumn(['memo_number', 'numbering_configuration_version_id', 'diagnosis', 'requested_parts_services']);
        });
    }
};
