<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G-19: work_order_planned_parts had no tenant_id at all — isolation was
 * indirect, controller-enforced only (checking the parent work_order's
 * tenant on every action). This adds the column, backfills every existing
 * row from its parent work_order (so no legacy row is left null), then
 * enforces NOT NULL + a real FK — closing the gap structurally rather than
 * relying on every future caller remembering to join through work_orders.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_planned_parts', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable()->after('id');
        });

        DB::statement(
            'UPDATE work_order_planned_parts wopp SET tenant_id = wo.tenant_id '.
            'FROM work_orders wo WHERE wo.id = wopp.work_order_id AND wopp.tenant_id IS NULL'
        );

        $orphaned = DB::table('work_order_planned_parts')->whereNull('tenant_id')->count();
        if ($orphaned > 0) {
            throw new RuntimeException(
                "Cannot enforce NOT NULL on work_order_planned_parts.tenant_id: {$orphaned} row(s) have no ".
                'resolvable parent work_order. Resolve or remove these orphaned rows before re-running this migration.'
            );
        }

        Schema::table('work_order_planned_parts', function (Blueprint $table) {
            $table->uuid('tenant_id')->nullable(false)->change();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->index(['tenant_id', 'work_order_id']);
        });
    }

    public function down(): void
    {
        Schema::table('work_order_planned_parts', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropIndex(['tenant_id', 'work_order_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
