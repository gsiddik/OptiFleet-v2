<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final reconciliation: the VMS Sparepart return/processing flow lists a
 * photo field alongside partner/date/receiver/note. Mirrors the identical
 * `evidence` (nullable string — a URL/path, not a file upload endpoint)
 * pattern already used by RoadTest, InspectionFinding, Breakdown, and
 * WarrantyClaim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_part_returns', function (Blueprint $table) {
            $table->string('evidence')->nullable()->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_part_returns', function (Blueprint $table) {
            $table->dropColumn('evidence');
        });
    }
};
