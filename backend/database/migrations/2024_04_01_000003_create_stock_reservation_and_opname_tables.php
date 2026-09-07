<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('warehouse_id');
            $table->uuid('work_order_id');
            $table->enum('status', ['DRAFT', 'RESERVED', 'PARTIALLY_RESERVED', 'RELEASED', 'CONSUMED', 'CANCELLED'])->default('DRAFT');
            $table->uuid('created_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->unique(['work_order_id', 'warehouse_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('stock_reservation_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('stock_reservation_id');
            $table->uuid('product_id');
            $table->uuid('work_order_planned_part_id')->nullable();
            $table->decimal('requested_quantity', 16, 4);
            $table->decimal('reserved_quantity', 16, 4)->default(0);
            $table->timestamps();

            $table->foreign('stock_reservation_id')->references('id')->on('stock_reservations')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->index(['stock_reservation_id']);
        });

        Schema::create('stock_opnames', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('warehouse_id');
            $table->string('opname_number');
            $table->enum('status', ['DRAFT', 'COUNTING', 'SUBMITTED', 'APPROVED', 'POSTED'])->default('DRAFT');
            $table->uuid('created_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('posted_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->unique(['tenant_id', 'opname_number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('stock_opname_id');
            $table->uuid('product_id');
            $table->decimal('system_quantity', 16, 4);
            $table->decimal('physical_quantity', 16, 4)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('stock_opname_id')->references('id')->on('stock_opnames')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->unique(['stock_opname_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
        Schema::dropIfExists('stock_opnames');
        Schema::dropIfExists('stock_reservation_items');
        Schema::dropIfExists('stock_reservations');
    }
};
