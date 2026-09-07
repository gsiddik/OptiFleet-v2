<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('wo_number')->unique();
            $table->uuid('tenant_id');
            $table->uuid('branch_id');
            $table->uuid('workshop_id');
            $table->uuid('workspace_id')->nullable(); // FK added once workspaces exists (2024_03_01_000008)
            $table->uuid('vehicle_id');
            $table->uuid('maintenance_request_id')->nullable();
            $table->uuid('maintenance_schedule_id')->nullable();
            $table->uuid('breakdown_id')->nullable();
            $table->enum('maintenance_type', ['PREVENTIVE', 'CORRECTIVE', 'BREAKDOWN', 'INSPECTION', 'CAMPAIGN']);
            $table->enum('priority', ['LOW', 'MEDIUM', 'HIGH', 'URGENT'])->default('MEDIUM');
            $table->text('complaint')->nullable();
            $table->decimal('current_odometer', 12, 2)->nullable();
            $table->decimal('engine_hour', 12, 2)->nullable();
            $table->timestamp('target_start_at')->nullable();
            $table->timestamp('target_completion_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->enum('status', [
                'DRAFT', 'SUBMITTED', 'APPROVED', 'ASSIGNED', 'SCHEDULED', 'IN_PROGRESS',
                'ON_HOLD', 'WAITING_PART', 'QC_PENDING', 'REWORK', 'COMPLETED', 'CLOSED',
                'REJECTED', 'CANCELLED',
            ])->default('DRAFT');
            $table->uuid('created_by')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('workshop_id')->references('id')->on('workshops')->restrictOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->restrictOnDelete();
            $table->foreign('maintenance_request_id')->references('id')->on('maintenance_requests')->nullOnDelete();
            $table->foreign('maintenance_schedule_id')->references('id')->on('maintenance_schedules')->nullOnDelete();
            $table->foreign('breakdown_id')->references('id')->on('breakdowns')->nullOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'workshop_id']);
            $table->index(['tenant_id', 'vehicle_id']);
        });

        Schema::table('maintenance_requests', function (Blueprint $table) {
            $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
        });
        Schema::table('breakdowns', function (Blueprint $table) {
            $table->foreign('work_order_id')->references('id')->on('work_orders')->nullOnDelete();
        });
        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->foreign('last_completed_work_order_id')->references('id')->on('work_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_schedules', fn (Blueprint $t) => $t->dropForeign(['last_completed_work_order_id']));
        Schema::table('breakdowns', fn (Blueprint $t) => $t->dropForeign(['work_order_id']));
        Schema::table('maintenance_requests', fn (Blueprint $t) => $t->dropForeign(['work_order_id']));
        Schema::dropIfExists('work_orders');
    }
};
