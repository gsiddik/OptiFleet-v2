<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * EXTERNAL: a top-level Work Order status parallel to IN_PROGRESS (the
 * work is being carried out by an external workshop) — see
 * AddWorkOrderExternalStatusSeeder for the workflow-graph side of this.
 * The workflow engine alone cannot make Postgres accept the new value:
 * $table->enum() on Postgres compiles to a plain CHECK constraint listing
 * the original values, so it must be widened here too (same pattern as
 * 2026_09_12_000002_add_consume_to_stock_movement_type.php).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT work_orders_status_check');
        DB::statement(
            'ALTER TABLE work_orders ADD CONSTRAINT work_orders_status_check '.
            'CHECK (status::text = ANY (ARRAY['.
            "'DRAFT','SUBMITTED','APPROVED','REJECTED','ASSIGNED','SCHEDULED','IN_PROGRESS','QC_PENDING',".
            "'ON_HOLD','WAITING_PART','REWORK','COMPLETED','CLOSED','CANCELLED','EXTERNAL'".
            ']::character varying[]))'
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE work_orders DROP CONSTRAINT work_orders_status_check');
        DB::statement(
            'ALTER TABLE work_orders ADD CONSTRAINT work_orders_status_check '.
            'CHECK (status::text = ANY (ARRAY['.
            "'DRAFT','SUBMITTED','APPROVED','REJECTED','ASSIGNED','SCHEDULED','IN_PROGRESS','QC_PENDING',".
            "'ON_HOLD','WAITING_PART','REWORK','COMPLETED','CLOSED','CANCELLED'".
            ']::character varying[]))'
        );
    }
};
