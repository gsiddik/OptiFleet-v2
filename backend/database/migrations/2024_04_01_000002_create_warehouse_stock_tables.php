<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_stocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('warehouse_id');
            $table->uuid('product_id');
            $table->decimal('quantity_on_hand', 16, 4)->default(0);
            $table->decimal('quantity_reserved', 16, 4)->default(0);
            $table->decimal('minimum_stock', 16, 4)->default(0);
            $table->decimal('maximum_stock', 16, 4)->nullable();
            $table->decimal('reorder_point', 16, 4)->default(0);
            $table->decimal('average_unit_cost', 16, 4)->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['warehouse_id', 'product_id']);
            $table->index(['tenant_id', 'product_id']);
        });

        // Append-only ledger every stock-affecting action writes to; balances
        // in warehouse_stocks must always be reconstructable by summing this
        // table's signed quantities per (warehouse_id, product_id) — nothing
        // updates quantity_on_hand/quantity_reserved without one of these.
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('warehouse_id');
            $table->uuid('product_id');
            $table->enum('movement_type', [
                'OPENING', 'RECEIPT', 'RESERVATION', 'RELEASE_RESERVATION', 'ISSUE', 'RETURN',
                'TRANSFER_OUT', 'TRANSFER_IN', 'ADJUSTMENT_PLUS', 'ADJUSTMENT_MINUS', 'STOCK_OPNAME', 'SCRAP',
            ]);
            $table->decimal('quantity', 16, 4);
            $table->decimal('unit_cost', 16, 4)->nullable();
            $table->string('reference_type')->nullable();
            $table->uuid('reference_id')->nullable();
            $table->timestamp('occurred_at');
            $table->uuid('created_by')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->index(['tenant_id', 'warehouse_id', 'product_id']);
            $table->index(['reference_type', 'reference_id']);
            $table->index(['tenant_id', 'movement_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('warehouse_stocks');
    }
};
