<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('work_order_id');
            $table->uuid('component_group_id')->nullable();
            $table->enum('severity', ['INFO', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])->default('MEDIUM');
            $table->text('description');
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('component_group_id')->references('id')->on('component_groups')->nullOnDelete();
        });

        Schema::create('work_order_diagnoses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('work_order_id');
            $table->uuid('work_order_finding_id')->nullable();
            $table->text('root_cause');
            $table->text('notes')->nullable();
            $table->uuid('diagnosed_by')->nullable();
            $table->timestamp('diagnosed_at')->nullable();
            $table->timestamps();

            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('work_order_finding_id')->references('id')->on('work_order_findings')->nullOnDelete();
        });

        Schema::create('work_order_corrective_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('work_order_id');
            $table->uuid('work_order_diagnosis_id')->nullable();
            $table->text('action_description');
            $table->enum('status', ['PLANNED', 'IN_PROGRESS', 'DONE'])->default('PLANNED');
            $table->uuid('performed_by')->nullable();
            $table->timestamps();

            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('work_order_diagnosis_id')->references('id')->on('work_order_diagnoses')->nullOnDelete();
        });

        Schema::create('maintenance_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('work_order_id');
            $table->uuid('component_group_id')->nullable();
            $table->string('service_item')->nullable();
            $table->text('description');
            $table->decimal('estimated_hours', 8, 2)->nullable();
            $table->decimal('actual_hours', 8, 2)->nullable();
            $table->enum('status', ['PENDING', 'ASSIGNED', 'IN_PROGRESS', 'ON_HOLD', 'COMPLETED', 'CANCELLED'])->default('PENDING');
            $table->uuid('assigned_mechanic')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('component_group_id')->references('id')->on('component_groups')->nullOnDelete();
            $table->index(['work_order_id', 'status']);
        });

        Schema::create('work_order_planned_parts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('work_order_id');
            $table->uuid('maintenance_job_id')->nullable();
            $table->string('product_reference')->nullable();
            $table->string('description');
            $table->decimal('quantity', 10, 2)->default(1);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('maintenance_job_id')->references('id')->on('maintenance_jobs')->nullOnDelete();
        });

        Schema::create('work_order_additional_works', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('work_order_id');
            $table->text('description');
            $table->uuid('requested_by')->nullable();
            $table->enum('status', ['REQUESTED', 'APPROVED', 'REJECTED'])->default('REQUESTED');
            $table->timestamp('requested_at')->nullable();
            $table->uuid('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_additional_works');
        Schema::dropIfExists('work_order_planned_parts');
        Schema::dropIfExists('maintenance_jobs');
        Schema::dropIfExists('work_order_corrective_actions');
        Schema::dropIfExists('work_order_diagnoses');
        Schema::dropIfExists('work_order_findings');
    }
};
