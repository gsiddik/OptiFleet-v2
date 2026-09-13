<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final reconciliation (queued ADJUST): `evidence` (added earlier this
 * batch) is only captured at return-creation time, wired through
 * WorkOrderExecutionController::returnPart(). The actual Used Sparepart
 * disposition flow (UsedPartDispositionController::inspect()) had no way
 * for the inspector to attach their OWN evidence photo, distinct from the
 * returner's — a separate nullable string column, same shallow URL
 * pattern as `evidence`, rather than overwriting the returner's photo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_part_returns', function (Blueprint $table) {
            $table->string('inspection_evidence')->nullable()->after('inspection_notes');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_part_returns', function (Blueprint $table) {
            $table->dropColumn('inspection_evidence');
        });
    }
};
