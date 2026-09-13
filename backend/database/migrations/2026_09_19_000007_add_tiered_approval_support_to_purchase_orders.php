<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G-06 (final reconciliation): "PO approval single-tier, no reject-reason,
 * no signature." A generic, tenant-configurable multi-step approval engine
 * (WorkflowApprovalService + WorkflowEngine's approval_rule on a
 * transition, condition_set-gated) has existed since Phase 5 and is
 * already resource-type-agnostic — this wires Purchase Order into it
 * rather than building a bespoke mechanism, exactly as the gap report's
 * own recommendation says.
 *
 * PENDING_APPROVAL is a new intermediate status: SUBMITTED -> (approve
 * action) -> PENDING_APPROVAL while a configured multi-step approval is
 * in flight -> APPROVED once every step decides. No thresholds, tier
 * counts, or approver roles are defined here or anywhere in code — a
 * tenant that never publishes a purchase_order workflow configuration
 * with an approval_rule on its 'approve' transition sees zero behavior
 * change: approve() still transitions SUBMITTED -> APPROVED directly.
 * This is framework only; production activation requires an actual
 * tenant to author and publish real tiers via the existing Configuration
 * UI (POST /app/configuration/versions + /publish), the same as Tire
 * Scoring's own production-activation gate.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE purchase_orders DROP CONSTRAINT purchase_orders_status_check');
        DB::statement(
            'ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check '.
            'CHECK (status::text = ANY (ARRAY['.
            "'DRAFT','SUBMITTED','PENDING_APPROVAL','APPROVED','ISSUED','PARTIALLY_RECEIVED',".
            "'RECEIVED','CLOSED','REJECTED','CANCELLED'".
            ']::character varying[]))'
        );

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->uuid('workflow_approval_request_id')->nullable()->after('workflow_configuration_version_id');
            $table->foreign('workflow_approval_request_id')->references('id')->on('workflow_approval_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign(['workflow_approval_request_id']);
            $table->dropColumn('workflow_approval_request_id');
        });

        DB::statement('ALTER TABLE purchase_orders DROP CONSTRAINT purchase_orders_status_check');
        DB::statement(
            'ALTER TABLE purchase_orders ADD CONSTRAINT purchase_orders_status_check '.
            'CHECK (status::text = ANY (ARRAY['.
            "'DRAFT','SUBMITTED','APPROVED','ISSUED','PARTIALLY_RECEIVED',".
            "'RECEIVED','CLOSED','REJECTED','CANCELLED'".
            ']::character varying[]))'
        );
    }
};
