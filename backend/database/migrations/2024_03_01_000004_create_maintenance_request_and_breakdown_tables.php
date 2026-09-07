<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('breakdowns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('branch_id');
            $table->uuid('vehicle_id');
            $table->uuid('reported_by')->nullable();
            $table->timestamp('reported_at');
            $table->string('location')->nullable();
            $table->enum('severity', ['MINOR', 'MAJOR', 'IMMOBILIZED']);
            $table->text('description');
            $table->string('evidence')->nullable();
            $table->text('response_notes')->nullable();
            $table->timestamp('downtime_start_at')->nullable();
            $table->enum('status', ['REPORTED', 'VERIFIED', 'ASSESSED', 'REPAIR_REQUIRED', 'WORK_ORDER_CREATED', 'RESOLVED'])->default('REPORTED');
            $table->uuid('maintenance_request_id')->nullable();
            $table->uuid('work_order_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'vehicle_id']);
        });

        Schema::create('maintenance_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('request_number')->unique();
            $table->uuid('tenant_id');
            $table->uuid('branch_id');
            $table->uuid('workshop_id')->nullable();
            $table->uuid('vehicle_id');
            $table->uuid('component_group_id')->nullable();
            $table->uuid('category_id')->nullable(); // extension point: future request-category master
            $table->enum('source_type', ['USER', 'INSPECTION', 'SCHEDULE', 'BREAKDOWN', 'TELEMATICS', 'MECHANIC']);
            $table->uuid('source_inspection_id')->nullable();
            $table->uuid('source_schedule_id')->nullable();
            $table->uuid('source_breakdown_id')->nullable();
            $table->enum('priority', ['LOW', 'MEDIUM', 'HIGH', 'URGENT'])->default('MEDIUM');
            $table->text('complaint');
            $table->uuid('requested_by')->nullable();
            $table->enum('status', ['DRAFT', 'SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'WORK_ORDER_CREATED', 'REJECTED', 'NEED_INFORMATION', 'CANCELLED'])->default('DRAFT');
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->uuid('work_order_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('workshop_id')->references('id')->on('workshops')->nullOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('component_group_id')->references('id')->on('component_groups')->nullOnDelete();
            $table->foreign('source_inspection_id')->references('id')->on('inspections')->nullOnDelete();
            $table->foreign('source_schedule_id')->references('id')->on('maintenance_schedules')->nullOnDelete();
            $table->foreign('source_breakdown_id')->references('id')->on('breakdowns')->nullOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'vehicle_id']);
        });

        Schema::table('breakdowns', function (Blueprint $table) {
            $table->foreign('maintenance_request_id')->references('id')->on('maintenance_requests')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('breakdowns', fn (Blueprint $t) => $t->dropForeign(['maintenance_request_id']));
        Schema::dropIfExists('maintenance_requests');
        Schema::dropIfExists('breakdowns');
    }
};
