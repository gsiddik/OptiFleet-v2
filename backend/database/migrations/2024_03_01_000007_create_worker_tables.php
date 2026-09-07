<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('employee_code');
            $table->string('name');
            $table->uuid('branch_id');
            $table->uuid('workshop_id')->nullable();
            $table->enum('worker_type', ['LEAD_MECHANIC', 'MECHANIC', 'TECHNICIAN', 'INSPECTOR', 'QC'])->default('MECHANIC');
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->uuid('user_id')->nullable(); // optional link to a login account
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('workshop_id')->references('id')->on('workshops')->nullOnDelete();
            $table->unique(['tenant_id', 'employee_code']);
            $table->index(['tenant_id', 'workshop_id']);
        });

        Schema::create('worker_skills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('worker_id');
            $table->uuid('component_group_id');
            $table->unsignedTinyInteger('skill_level')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('worker_id')->references('id')->on('workers')->cascadeOnDelete();
            $table->foreign('component_group_id')->references('id')->on('component_groups')->cascadeOnDelete();
            $table->unique(['worker_id', 'component_group_id']);
        });

        Schema::create('workshop_worker_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('worker_id');
            $table->uuid('from_branch_id')->nullable();
            $table->uuid('to_branch_id')->nullable();
            $table->uuid('from_workshop_id')->nullable();
            $table->uuid('to_workshop_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_until')->nullable();
            $table->uuid('assigned_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('worker_id')->references('id')->on('workers')->cascadeOnDelete();
            $table->index(['tenant_id', 'worker_id']);
        });

        Schema::create('work_order_mechanic_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('work_order_id');
            $table->uuid('maintenance_job_id')->nullable();
            $table->uuid('worker_id');
            $table->enum('role', ['PRIMARY', 'ASSISTANT'])->default('PRIMARY');
            $table->timestamp('assigned_at');
            $table->uuid('assigned_by')->nullable();
            $table->timestamp('unassigned_at')->nullable();
            $table->timestamps();

            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('maintenance_job_id')->references('id')->on('maintenance_jobs')->nullOnDelete();
            $table->foreign('worker_id')->references('id')->on('workers')->cascadeOnDelete();
            $table->index(['work_order_id', 'worker_id']);
        });

        Schema::create('work_order_labor_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('maintenance_job_id');
            $table->uuid('worker_id');
            $table->enum('status', ['RUNNING', 'PAUSED', 'FINISHED'])->default('RUNNING');
            $table->timestamp('started_at');
            $table->unsignedInteger('paused_duration_minutes')->default(0);
            $table->timestamp('last_paused_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('actual_minutes')->nullable();
            $table->timestamps();

            $table->foreign('maintenance_job_id')->references('id')->on('maintenance_jobs')->cascadeOnDelete();
            $table->foreign('worker_id')->references('id')->on('workers')->cascadeOnDelete();
            $table->index(['maintenance_job_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_labor_logs');
        Schema::dropIfExists('work_order_mechanic_assignments');
        Schema::dropIfExists('workshop_worker_assignments');
        Schema::dropIfExists('worker_skills');
        Schema::dropIfExists('workers');
    }
};
