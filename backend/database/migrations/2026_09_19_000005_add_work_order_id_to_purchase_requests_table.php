<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-08 (final reconciliation, first clause): the VMS Stock Request page
 * shows "Requestor, linked Work Order, requested stock items after WO
 * selection" as a distinct capability. `source_type = 'WORK_ORDER'` has
 * existed on purchase_requests since Phase 4 but was never wired to an
 * actual Work Order — this adds the missing link. The second clause
 * (line-level hold/reject-reason) is a separate, still-open item — see
 * IMPROVEMENT_CONTEXT.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->uuid('work_order_id')->nullable()->after('source_reference');
            $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropForeign(['work_order_id']);
            $table->dropColumn('work_order_id');
        });
    }
};
