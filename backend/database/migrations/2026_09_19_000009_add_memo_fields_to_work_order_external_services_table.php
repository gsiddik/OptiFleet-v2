<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-13 (final reconciliation, partial): the VMS Maintenance Memo form
 * lists Condition, Before Photo, and Priority alongside partner/unit/
 * date/problem — all already captured here via work_order_id (unit),
 * partner_id, requested_at (date), and description (problem). Adds the
 * three still-missing descriptive fields. Deliberately does NOT add a
 * "Type Scheduled/Unscheduled/Accident" field — WorkOrder.maintenance_type
 * already serves that classification role for the parent record, and a
 * second, possibly-conflicting classification on the child record would
 * duplicate data rather than fill a gap. "Save and Print Memo" and the
 * full Workshop Invoice settlement/payment cycle remain open — see
 * IMPROVEMENT_CONTEXT.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_external_services', function (Blueprint $table) {
            $table->string('photo_evidence')->nullable()->after('description');
            $table->text('condition_notes')->nullable()->after('photo_evidence');
            $table->enum('priority', ['LOW', 'MEDIUM', 'HIGH', 'URGENT'])->nullable()->after('condition_notes');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_external_services', function (Blueprint $table) {
            $table->dropColumn(['photo_evidence', 'condition_notes', 'priority']);
        });
    }
};
