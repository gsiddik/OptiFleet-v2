<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated External Workshop business rules: a Work Order must
 * distinguish regular/internal from External-destination work independent
 * of its current status, since a revised External Work Order returns to
 * DRAFT while remaining External. `execution_mode` is that persistent
 * flag; `external_finalized_revision` tracks the finalized revision number
 * (0 = never finalized, incremented only on finalize/re-finalize, never on
 * entering Draft or printing). `cancellation_reason` is a small,
 * backward-compatible, generically-usable column (not External-specific
 * at the schema level, though only the External cancel flow currently
 * requires it to be non-null).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->string('execution_mode')->default('INTERNAL')->after('status');
            $table->unsignedInteger('external_finalized_revision')->default(0)->after('execution_mode');
            $table->text('cancellation_reason')->nullable()->after('external_finalized_revision');
        });

        DB::statement("ALTER TABLE work_orders ADD CONSTRAINT work_orders_execution_mode_check CHECK (execution_mode::text = ANY (ARRAY['INTERNAL','EXTERNAL']::character varying[]))");

        // Backward-compatible: existing Work Orders are all INTERNAL — no explicit data rule
        // assigns any existing row to External mode (default value already covers this).

        Schema::create('work_order_external_references', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('branch_id');
            $table->uuid('work_order_id');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            // One Work Order maps to at most one Workshop Invoice source reference — reused across
            // revise/re-finalize cycles, never duplicated. Its "active"/NEW_EXTERNAL_WO eligibility is
            // derived from the parent Work Order's live status (EXTERNAL = active), not stored here.
            $table->unique('work_order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_external_references');

        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT work_orders_execution_mode_check');

        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn(['execution_mode', 'external_finalized_revision', 'cancellation_reason']);
        });
    }
};
