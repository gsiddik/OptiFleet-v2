<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a serialized unit (component asset / tire) left the warehouse ledger when it was installed.
 * One row per installation (unique), written in the installation's own transaction by
 * SerializedStockExitService — the audit basis for the "exactly once" rule and for reconciliation:
 *  DIRECT_ISSUE  the installation itself issued the unit from the warehouse (stock_movement_id);
 *  WO_ISSUE      a Work Order Part Request issue had already taken it out (planned_part_id), no movement;
 *  NOT_LEDGERED  the unit was never (or no longer) counted in the warehouse ledger — no movement
 *                (reason: NO_WAREHOUSE, PREVIOUSLY_INSTALLED, USED_STOCK).
 * Installations that predate this table have no row; nothing is backfilled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('installation_stock_exits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('asset_type', 20); // COMPONENT_ASSET | TIRE
            $table->uuid('asset_id');
            $table->uuid('installation_id');
            $table->uuid('product_id');
            $table->uuid('warehouse_id')->nullable();
            $table->string('source', 20);
            $table->string('reason', 30)->nullable();
            $table->uuid('stock_movement_id')->nullable();
            $table->uuid('planned_part_id')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('stock_movement_id')->references('id')->on('stock_movements');
            $table->foreign('planned_part_id')->references('id')->on('work_order_planned_parts');
            $table->unique('installation_id');
            $table->index(['tenant_id', 'asset_type', 'asset_id']);
            $table->index('planned_part_id');
        });
        DB::statement("ALTER TABLE installation_stock_exits ADD CONSTRAINT installation_stock_exits_source CHECK (source IN ('DIRECT_ISSUE','WO_ISSUE','NOT_LEDGERED'))");
        DB::statement("ALTER TABLE installation_stock_exits ADD CONSTRAINT installation_stock_exits_shape CHECK (
            (source = 'DIRECT_ISSUE' AND stock_movement_id IS NOT NULL AND planned_part_id IS NULL)
            OR (source = 'WO_ISSUE' AND planned_part_id IS NOT NULL AND stock_movement_id IS NULL)
            OR (source = 'NOT_LEDGERED' AND stock_movement_id IS NULL AND planned_part_id IS NULL))");
    }

    public function down(): void
    {
        Schema::dropIfExists('installation_stock_exits');
    }
};
