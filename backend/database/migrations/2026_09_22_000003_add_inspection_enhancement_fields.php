<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 9: templates get a system-mandatory, non-removable "Odometer"
 * checklist item; is_system distinguishes it from user-added items.
 * Section 9 (historical snapshot): the checklist composition used by an
 * Inspection must survive later template edits, so a JSON snapshot is
 * captured on the Inspection at creation time rather than always reading
 * the live (mutable) template.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inspection_template_items', function (Blueprint $table) {
            $table->boolean('is_system')->default(false)->after('status');
        });

        Schema::table('inspections', function (Blueprint $table) {
            $table->json('template_snapshot')->nullable()->after('inspection_template_id');
        });
    }

    public function down(): void
    {
        Schema::table('inspection_template_items', function (Blueprint $table) {
            $table->dropColumn('is_system');
        });

        Schema::table('inspections', function (Blueprint $table) {
            $table->dropColumn('template_snapshot');
        });
    }
};
