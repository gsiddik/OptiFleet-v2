<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G-04): a Work Order could previously reach COMPLETED/CLOSED
 * while a recorded finding was never followed up — work_order_findings
 * had no resolution concept at all, and qc_findings had a `resolved`
 * boolean with no API to ever set it away from its default false. This
 * gives both a real, actor-tracked resolution, and
 * WorkOrderClosureGuardService now blocks closure while either has an
 * unresolved entry tied to the Work Order (see that service).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_findings', function (Blueprint $table) {
            $table->enum('status', ['OPEN', 'RESOLVED'])->default('OPEN')->after('description');
            $table->text('resolution_notes')->nullable()->after('status');
            $table->uuid('resolved_by')->nullable()->after('resolution_notes');
            $table->timestamp('resolved_at')->nullable()->after('resolved_by');
        });

        Schema::table('qc_findings', function (Blueprint $table) {
            $table->uuid('resolved_by')->nullable()->after('resolved');
            $table->timestamp('resolved_at')->nullable()->after('resolved_by');
        });
    }

    public function down(): void
    {
        Schema::table('work_order_findings', function (Blueprint $table) {
            $table->dropColumn(['status', 'resolution_notes', 'resolved_by', 'resolved_at']);
        });

        Schema::table('qc_findings', function (Blueprint $table) {
            $table->dropColumn(['resolved_by', 'resolved_at']);
        });
    }
};
