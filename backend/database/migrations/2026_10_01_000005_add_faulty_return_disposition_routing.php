<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Faulty returned new parts: QUARANTINED is no longer a final state. Returned Parts Processing
 * routes a quarantined return to its follow-up disposition (WARRANTY_CLAIM, REPAIR or SCRAP).
 * The follow-up processing itself is a later scope; the routed return never re-enters stock.
 * Additive only: existing rows keep their status.
 */
return new class extends Migration
{
    private const BASE_STATUSES = "'PENDING_PROCESSING','QUARANTINED','PENDING_RETURN','RESTOCKED','PENDING_INSPECTION','INSPECTED','PENDING_APPROVAL','REJECTED','FINALIZED'";

    public function up(): void
    {
        Schema::table('work_order_part_returns', function (Blueprint $table) {
            $table->uuid('routed_by')->nullable();
            $table->timestamp('routed_at')->nullable();
        });

        DB::statement('ALTER TABLE work_order_part_returns DROP CONSTRAINT work_order_part_returns_disposition_status_check');
        DB::statement('ALTER TABLE work_order_part_returns ADD CONSTRAINT work_order_part_returns_disposition_status_check CHECK (disposition_status IN ('.self::BASE_STATUSES.",'WARRANTY_CLAIM','REPAIR','SCRAP'))");
    }

    public function down(): void
    {
        // Routed rows fall back to QUARANTINED (still outside available stock) so the old constraint holds.
        DB::table('work_order_part_returns')->whereIn('disposition_status', ['WARRANTY_CLAIM', 'REPAIR', 'SCRAP'])->update(['disposition_status' => 'QUARANTINED']);
        DB::statement('ALTER TABLE work_order_part_returns DROP CONSTRAINT work_order_part_returns_disposition_status_check');
        DB::statement('ALTER TABLE work_order_part_returns ADD CONSTRAINT work_order_part_returns_disposition_status_check CHECK (disposition_status IN ('.self::BASE_STATUSES.'))');
        Schema::table('work_order_part_returns', function (Blueprint $table) {
            $table->dropColumn(['routed_by', 'routed_at']);
        });
    }
};
