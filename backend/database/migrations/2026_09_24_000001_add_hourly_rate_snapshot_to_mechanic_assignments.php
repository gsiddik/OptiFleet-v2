<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Findings/Diagnosis/Corrective Actions/Jobs/Mechanic Assignment cost
 * estimation gap: a mechanic assignment previously carried no record of
 * the worker's hourly rate at the time they were assigned, so a later
 * rate change would silently change the cost basis of already-assigned
 * work. Snapshotting it here follows the same pattern already used for
 * part costs (work_order_planned_parts.unit_cost_at_issue).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_mechanic_assignments', function (Blueprint $table) {
            $table->decimal('hourly_rate_snapshot', 10, 4)->nullable()->after('worker_id');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_mechanic_assignments', function (Blueprint $table) {
            $table->dropColumn('hourly_rate_snapshot');
        });
    }
};
