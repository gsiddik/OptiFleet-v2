<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-16: Sell Sparepart. A sale line always references the Phase B
 * disposition that made it eligible (work_order_part_return_id, NOT
 * NULL) — there is no path to sell a part that never passed inspection
 * and approval as SELL_ELIGIBLE. Money columns are decimal, never float,
 * per the project's monetary-safety convention.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('spare_part_sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('work_order_part_return_id');
            $table->uuid('product_id');
            $table->uuid('warehouse_id');
            $table->decimal('quantity', 16, 4);
            $table->enum('sale_type', ['OPERATIONAL_REUSE', 'SCRAP_MATERIAL']);
            $table->enum('buyer_type', ['PARTNER', 'EXTERNAL']);
            $table->uuid('partner_id')->nullable();
            $table->string('buyer_name')->nullable();
            $table->decimal('unit_price', 16, 4);
            $table->decimal('total_amount', 16, 4);
            $table->enum('status', ['DRAFT', 'PENDING_APPROVAL', 'APPROVED', 'REJECTED', 'CANCELLED'])->default('DRAFT');
            $table->uuid('workflow_configuration_version_id')->nullable();
            $table->uuid('workflow_approval_request_id')->nullable();
            $table->uuid('stock_movement_id')->nullable();
            $table->uuid('requested_by')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('work_order_part_return_id')->references('id')->on('work_order_part_returns')->restrictOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->restrictOnDelete();
            $table->foreign('partner_id')->references('id')->on('partners')->nullOnDelete();
            $table->foreign('workflow_configuration_version_id')->references('id')->on('configuration_versions')->nullOnDelete();
            $table->foreign('workflow_approval_request_id')->references('id')->on('workflow_approval_requests')->nullOnDelete();
            $table->foreign('stock_movement_id')->references('id')->on('stock_movements')->nullOnDelete();
            $table->index(['tenant_id', 'work_order_part_return_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spare_part_sales');
    }
};
