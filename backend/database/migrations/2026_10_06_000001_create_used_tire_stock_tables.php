<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Used tire warehouse quantity — kept apart from new-stock warehouse_stocks:
 *
 *  used_tire_stocks           quantity on hand of REUSE tires per warehouse + tire product
 *  used_tire_stock_movements  append-only ledger, one row per serial movement (+1 / −1)
 *
 * Part Request lines and planned parts get a stock_condition (NEW = warehouse_stocks, USED =
 * used_tire_stocks); every existing row is NEW. REUSE tires already in a warehouse are booked in
 * as an opening balance.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('used_tire_stocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('warehouse_id');
            $table->uuid('product_id');
            $table->integer('quantity_on_hand')->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('product_id')->references('id')->on('products');
            $table->unique(['warehouse_id', 'product_id']);
            $table->index(['tenant_id', 'product_id']);
        });
        DB::statement('ALTER TABLE used_tire_stocks ADD CONSTRAINT used_tire_stocks_quantity_check CHECK (quantity_on_hand >= 0)');

        Schema::create('used_tire_stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('warehouse_id');
            $table->uuid('product_id');
            $table->uuid('tire_id');
            $table->string('movement_type', 30);
            $table->smallInteger('quantity');
            $table->integer('balance_after');
            $table->string('reference_type')->nullable();
            $table->uuid('reference_id')->nullable();
            $table->string('reason')->nullable();
            $table->uuid('performed_by')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses');
            $table->foreign('product_id')->references('id')->on('products');
            $table->foreign('tire_id')->references('id')->on('tires');
            $table->index(['warehouse_id', 'product_id', 'occurred_at']);
            $table->index(['reference_type', 'reference_id']);
        });
        // Ledger order (several movements of one tire can share a timestamp).
        DB::statement('ALTER TABLE used_tire_stock_movements ADD COLUMN sequence bigint GENERATED ALWAYS AS IDENTITY');
        DB::statement('CREATE INDEX used_tire_stock_movements_tire_sequence_index ON used_tire_stock_movements (tire_id, sequence)');
        DB::statement('ALTER TABLE used_tire_stock_movements ADD CONSTRAINT used_tire_stock_movements_quantity_check CHECK (quantity IN (1, -1))');
        DB::statement("ALTER TABLE used_tire_stock_movements ADD CONSTRAINT used_tire_stock_movements_type_check CHECK (movement_type IN ('OPENING_BALANCE', 'INSPECTION_RECEIPT', 'ISSUE', 'RETURN', 'INSTALL', 'SCRAP', 'SALE'))");

        foreach (['work_order_part_request_items', 'work_order_planned_parts'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->string('stock_condition', 4)->default('NEW'));
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_stock_condition_check CHECK (stock_condition IN ('NEW', 'USED'))");
        }

        $reuse = DB::table('tires')->whereNull('deleted_at')->where('current_status', 'REUSE')
            ->whereNotNull('current_warehouse_id')->whereNull('current_vehicle_id')
            ->orderBy('created_at')->get(['id', 'tenant_id', 'product_id', 'current_warehouse_id']);
        foreach ($reuse as $tire) {
            $now = now();
            $stock = DB::table('used_tire_stocks')->where('warehouse_id', $tire->current_warehouse_id)->where('product_id', $tire->product_id)->first();
            if ($stock) {
                DB::table('used_tire_stocks')->where('id', $stock->id)->update(['quantity_on_hand' => $stock->quantity_on_hand + 1, 'updated_at' => $now]);
                $balance = $stock->quantity_on_hand + 1;
            } else {
                DB::table('used_tire_stocks')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tire->tenant_id, 'warehouse_id' => $tire->current_warehouse_id, 'product_id' => $tire->product_id, 'quantity_on_hand' => 1, 'created_at' => $now, 'updated_at' => $now]);
                $balance = 1;
            }
            DB::table('used_tire_stock_movements')->insert([
                'id' => (string) Str::uuid(), 'tenant_id' => $tire->tenant_id, 'warehouse_id' => $tire->current_warehouse_id,
                'product_id' => $tire->product_id, 'tire_id' => $tire->id, 'movement_type' => 'OPENING_BALANCE', 'quantity' => 1,
                'balance_after' => $balance, 'reason' => 'REUSE tire already in this warehouse', 'occurred_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        foreach (['work_order_part_request_items', 'work_order_planned_parts'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_stock_condition_check");
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('stock_condition'));
        }
        Schema::dropIfExists('used_tire_stock_movements');
        Schema::dropIfExists('used_tire_stocks');
    }
};
