<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qc_inspections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('work_order_id');
            $table->uuid('inspector_worker_id')->nullable();
            $table->enum('status', ['QC_PENDING', 'QC_STARTED', 'PASS', 'FAIL', 'COMPLETED'])->default('QC_PENDING');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('inspector_worker_id')->references('id')->on('workers')->nullOnDelete();
            $table->index(['tenant_id', 'work_order_id']);
        });

        Schema::create('qc_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('qc_inspection_id');
            $table->text('description');
            $table->enum('severity', ['INFO', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL'])->default('MEDIUM');
            $table->boolean('resolved')->default(false);
            $table->timestamps();

            $table->foreign('qc_inspection_id')->references('id')->on('qc_inspections')->cascadeOnDelete();
        });

        Schema::create('road_tests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('work_order_id');
            $table->uuid('tester_worker_id')->nullable();
            $table->decimal('start_odometer', 12, 2)->nullable();
            $table->decimal('end_odometer', 12, 2)->nullable();
            $table->unsignedInteger('duration_minutes')->nullable();
            $table->enum('result', ['PASS', 'FAIL', 'NOT_REQUIRED'])->default('NOT_REQUIRED');
            $table->text('notes')->nullable();
            $table->string('evidence')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('tester_worker_id')->references('id')->on('workers')->nullOnDelete();
        });

        Schema::create('vehicle_releases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('work_order_id')->unique();
            $table->uuid('vehicle_id');
            $table->timestamp('released_at');
            $table->uuid('released_by')->nullable();
            $table->decimal('release_odometer', 12, 2)->nullable();
            $table->string('release_condition')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->index(['tenant_id', 'vehicle_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_releases');
        Schema::dropIfExists('road_tests');
        Schema::dropIfExists('qc_findings');
        Schema::dropIfExists('qc_inspections');
    }
};
