<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('work_order_planned_parts', function (Blueprint $table) {
            $table->uuid('product_id')->nullable()->after('maintenance_job_id');
            $table->uuid('warehouse_id')->nullable()->after('product_id');
            $table->enum('status', [
                'PLANNED', 'REQUESTED', 'RESERVED', 'PARTIALLY_RESERVED', 'ISSUED',
                'PARTIALLY_ISSUED', 'CONSUMED', 'RETURNED', 'CANCELLED',
            ])->default('PLANNED')->after('quantity');
            $table->decimal('planned_quantity', 16, 4)->default(0)->after('status');
            $table->decimal('reserved_quantity', 16, 4)->default(0)->after('planned_quantity');
            $table->decimal('issued_quantity', 16, 4)->default(0)->after('reserved_quantity');
            $table->decimal('consumed_quantity', 16, 4)->default(0)->after('issued_quantity');
            $table->decimal('returned_quantity', 16, 4)->default(0)->after('consumed_quantity');
            $table->decimal('unit_cost_at_issue', 16, 4)->nullable()->after('returned_quantity');
            $table->decimal('total_cost', 16, 4)->nullable()->after('unit_cost_at_issue');

            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->nullOnDelete();
            $table->index(['work_order_id', 'product_id']);
        });

        // Backfill planned_quantity from the pre-existing `quantity` column so
        // Phase 3 rows (created before this column existed) stay consistent.
        \Illuminate\Support\Facades\DB::statement('UPDATE work_order_planned_parts SET planned_quantity = quantity');
    }

    public function down(): void
    {
        Schema::table('work_order_planned_parts', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropForeign(['warehouse_id']);
            $table->dropColumn([
                'product_id', 'warehouse_id', 'status', 'planned_quantity', 'reserved_quantity',
                'issued_quantity', 'consumed_quantity', 'returned_quantity', 'unit_cost_at_issue', 'total_cost',
            ]);
        });
    }
};
