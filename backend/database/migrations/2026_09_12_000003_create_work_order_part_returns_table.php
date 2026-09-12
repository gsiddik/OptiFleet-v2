<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-14: the "Received Sparepart Return" entity. Every return of an issued
 * planned part is recorded here with its condition. UNUSED_NEW returns are
 * restocked immediately (disposition RESTOCKED, stock_movement_id set).
 * USED_GOOD/USED_FAULTY returns never touch warehouse_stocks at return
 * time (disposition PENDING_INSPECTION, stock_movement_id null) — they
 * are controlled returned inventory awaiting the Used Sparepart Processing
 * workflow (Phase B / G-15), which is not part of this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_part_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('work_order_planned_part_id');
            $table->uuid('warehouse_id');
            $table->uuid('product_id');
            $table->decimal('quantity', 16, 4);
            $table->enum('condition', ['UNUSED_NEW', 'USED_GOOD', 'USED_FAULTY']);
            $table->enum('disposition_status', ['RESTOCKED', 'PENDING_INSPECTION'])->default('PENDING_INSPECTION');
            $table->uuid('stock_movement_id')->nullable();
            $table->uuid('returned_by')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('work_order_planned_part_id')->references('id')->on('work_order_planned_parts')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('stock_movement_id')->references('id')->on('stock_movements')->nullOnDelete();
            $table->index(['tenant_id', 'work_order_planned_part_id']);
            $table->index(['tenant_id', 'condition', 'disposition_status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_part_returns');
    }
};
