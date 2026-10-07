<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Approved correction of ONE serialized installation whose unit never left the warehouse ledger. Schema only: nothing
 * is proposed or corrected by this migration. The correction itself is a normal ISSUE movement booked at the time of
 * the correction (never back-dated), referencing this row; the partial unique index allows at most one live
 * (pending / approved / applied) adjustment per installation, so a unit can never be corrected twice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reconciliation_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('installation_class', 120);
            $table->uuid('installation_id');
            $table->string('asset_type', 20);
            $table->uuid('asset_id');
            $table->uuid('product_id');
            $table->uuid('warehouse_id');
            $table->string('serial', 120)->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->decimal('quantity', 16, 4)->default(1);
            $table->string('status', 20)->default('PENDING_APPROVAL');
            $table->text('reason');
            $table->jsonb('evidence');
            $table->uuid('proposed_by');
            $table->timestamp('proposed_at');
            $table->uuid('workflow_configuration_version_id')->nullable();
            $table->uuid('workflow_approval_request_id')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->uuid('applied_by')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->uuid('stock_movement_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'warehouse_id', 'product_id']);
        });
        DB::statement("ALTER TABLE stock_reconciliation_adjustments ADD CONSTRAINT stock_reconciliation_adjustments_status_check CHECK (status IN ('PENDING_APPROVAL','APPROVED','APPLIED','REJECTED','SUPERSEDED'))");
        DB::statement("CREATE UNIQUE INDEX stock_reconciliation_adjustments_live_unique ON stock_reconciliation_adjustments (tenant_id, installation_id) WHERE status IN ('PENDING_APPROVAL','APPROVED','APPLIED')");
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_reconciliation_adjustments');
    }
};
