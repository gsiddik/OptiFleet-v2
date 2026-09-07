<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('transfer_number');
            $table->uuid('from_warehouse_id');
            $table->uuid('to_warehouse_id');
            $table->enum('status', [
                'DRAFT', 'REQUESTED', 'APPROVED', 'PREPARED', 'DISPATCHED', 'IN_TRANSIT',
                'RECEIVED', 'COMPLETED', 'REJECTED', 'CANCELLED',
            ])->default('DRAFT');
            $table->uuid('requested_by')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('from_warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('to_warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->unique(['tenant_id', 'transfer_number']);
            $table->index(['tenant_id', 'status']);
            $table->index(['from_warehouse_id', 'status']);
            $table->index(['to_warehouse_id', 'status']);
        });

        Schema::create('stock_transfer_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('stock_transfer_id');
            $table->uuid('product_id');
            $table->decimal('quantity_sent', 16, 4);
            $table->decimal('quantity_received', 16, 4)->nullable();
            $table->decimal('quantity_damaged', 16, 4)->default(0);
            $table->decimal('quantity_lost', 16, 4)->default(0);
            $table->decimal('unit_cost', 16, 4)->nullable();
            $table->text('discrepancy_reason')->nullable();
            $table->timestamps();

            $table->foreign('stock_transfer_id')->references('id')->on('stock_transfers')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->index(['stock_transfer_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfer_items');
        Schema::dropIfExists('stock_transfers');
    }
};
