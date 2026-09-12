<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G-15: extends the "Received Sparepart Return" record from Phase A
 * (work_order_part_returns) with the inspect -> propose disposition ->
 * approve/reject -> finalize lifecycle. disposition_status widens from
 * {RESTOCKED, PENDING_INSPECTION} to add {INSPECTED, PENDING_APPROVAL,
 * REJECTED, FINALIZED}; `disposition` records which outcome
 * (REPAIR/REUSE/QUARANTINE/SCRAP/SELL_ELIGIBLE) a FINALIZED row landed on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_part_returns', function (Blueprint $table) {
            $table->uuid('workflow_configuration_version_id')->nullable()->after('disposition_status');
            $table->decimal('accepted_quantity', 16, 4)->nullable()->after('workflow_configuration_version_id');
            $table->uuid('inspected_by')->nullable()->after('accepted_quantity');
            $table->timestamp('inspected_at')->nullable()->after('inspected_by');
            $table->text('inspection_notes')->nullable()->after('inspected_at');
            $table->enum('disposition', ['REPAIR', 'REUSE', 'QUARANTINE', 'SCRAP', 'SELL_ELIGIBLE'])->nullable()->after('inspection_notes');
            $table->text('disposition_reason')->nullable()->after('disposition');
            $table->uuid('proposed_by')->nullable()->after('disposition_reason');
            $table->uuid('workflow_approval_request_id')->nullable()->after('proposed_by');
            $table->timestamp('finalized_at')->nullable()->after('workflow_approval_request_id');

            $table->foreign('workflow_configuration_version_id')->references('id')->on('configuration_versions')->nullOnDelete();
            $table->foreign('workflow_approval_request_id')->references('id')->on('workflow_approval_requests')->nullOnDelete();
        });

        DB::statement('ALTER TABLE work_order_part_returns DROP CONSTRAINT work_order_part_returns_disposition_status_check');
        DB::statement(
            'ALTER TABLE work_order_part_returns ADD CONSTRAINT work_order_part_returns_disposition_status_check '.
            "CHECK (disposition_status::text = ANY (ARRAY['RESTOCKED','PENDING_INSPECTION','INSPECTED','PENDING_APPROVAL','REJECTED','FINALIZED']::character varying[]))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE work_order_part_returns DROP CONSTRAINT work_order_part_returns_disposition_status_check');
        DB::statement(
            'ALTER TABLE work_order_part_returns ADD CONSTRAINT work_order_part_returns_disposition_status_check '.
            "CHECK (disposition_status::text = ANY (ARRAY['RESTOCKED','PENDING_INSPECTION']::character varying[]))"
        );

        Schema::table('work_order_part_returns', function (Blueprint $table) {
            $table->dropForeign(['workflow_configuration_version_id']);
            $table->dropForeign(['workflow_approval_request_id']);
            $table->dropColumn([
                'workflow_configuration_version_id', 'accepted_quantity', 'inspected_by', 'inspected_at',
                'inspection_notes', 'disposition', 'disposition_reason', 'proposed_by',
                'workflow_approval_request_id', 'finalized_at',
            ]);
        });
    }
};
