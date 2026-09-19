<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5: Request Parts — a genuinely separate mechanic-initiated
 * request/approval document, distinct from WorkOrderPlannedPart. A
 * request only ever affects stock indirectly: on approval, each
 * approved line creates/updates a WorkOrderPlannedPart (via the
 * existing WorkOrderExecutionService::addPlannedPart()), which then
 * flows through the already-established Reserve -> Issue ->
 * Consume/Return lifecycle. This table never mutates warehouse_stocks
 * itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_part_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('work_order_id');
            $table->text('notes')->nullable();
            $table->enum('status', ['REQUESTED', 'APPROVED', 'REJECTED', 'CANCELLED'])->default('REQUESTED');
            $table->uuid('requested_by')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->index(['tenant_id', 'work_order_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('work_order_part_request_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('part_request_id');
            $table->uuid('product_id')->nullable();
            $table->string('product_reference')->nullable();
            $table->string('description');
            $table->decimal('quantity_requested', 16, 4);
            $table->decimal('quantity_approved', 16, 4)->nullable();
            $table->uuid('planned_part_id')->nullable();
            $table->timestamps();

            $table->foreign('part_request_id', 'wo_part_req_items_request_fk')->references('id')->on('work_order_part_requests')->cascadeOnDelete();
            $table->foreign('product_id', 'wo_part_req_items_product_fk')->references('id')->on('products')->nullOnDelete();
            $table->foreign('planned_part_id', 'wo_part_req_items_planned_part_fk')->references('id')->on('work_order_planned_parts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_part_request_items');
        Schema::dropIfExists('work_order_part_requests');
    }
};
