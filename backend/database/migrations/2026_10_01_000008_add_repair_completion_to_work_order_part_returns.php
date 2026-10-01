<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Used Sparepart Processing — Repair → Reuse (owner decision): a finalized REPAIR item can be
 * marked repair-completed, which sends it back to INSPECTED for a new (approved) disposition.
 * Additive, nullable: existing rows are untouched (a REPAIR item without completion is
 * "repair pending").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_part_returns', function (Blueprint $table) {
            $table->timestamp('repair_completed_at')->nullable();
            $table->uuid('repair_completed_by')->nullable();
            $table->text('repair_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('work_order_part_returns', function (Blueprint $table) {
            $table->dropColumn(['repair_completed_at', 'repair_completed_by', 'repair_notes']);
        });
    }
};
