<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase G (G-02 Cost Estimation, G-03 Maintenance Result): work_orders
 * previously had no cost-estimate fields at all (only maintenance_jobs'
 * estimated_hours, which is labor time, not money) and no structured
 * post-completion result — completing a WO only stamped completed_at.
 * Amounts are decimal(16,4), matching every other money column in this
 * codebase (never a float column) — computed via BigDecimal, not native
 * PHP arithmetic (see WorkOrderService::estimate()).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->decimal('estimated_labor_cost', 16, 4)->nullable()->after('engine_hour');
            $table->decimal('estimated_parts_cost', 16, 4)->nullable()->after('estimated_labor_cost');
            $table->decimal('estimated_total_cost', 16, 4)->nullable()->after('estimated_parts_cost');
            $table->uuid('estimated_by')->nullable()->after('estimated_total_cost');
            $table->timestamp('estimated_at')->nullable()->after('estimated_by');

            $table->text('result_summary')->nullable()->after('closed_at');
            $table->uuid('result_recorded_by')->nullable()->after('result_summary');
            $table->timestamp('result_recorded_at')->nullable()->after('result_recorded_by');
        });
    }

    public function down(): void
    {
        Schema::table('work_orders', function (Blueprint $table) {
            $table->dropColumn([
                'estimated_labor_cost', 'estimated_parts_cost', 'estimated_total_cost', 'estimated_by', 'estimated_at',
                'result_summary', 'result_recorded_by', 'result_recorded_at',
            ]);
        });
    }
};
