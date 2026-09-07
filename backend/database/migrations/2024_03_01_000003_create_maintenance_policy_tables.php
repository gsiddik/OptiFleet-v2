<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_packages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code');
            $table->string('name');
            $table->enum('maintenance_type', ['PREVENTIVE', 'CORRECTIVE', 'BREAKDOWN', 'INSPECTION', 'CAMPAIGN'])->default('PREVENTIVE');
            $table->text('description')->nullable();
            $table->decimal('standard_labor_hours', 8, 2)->nullable();
            $table->enum('status', ['DRAFT', 'ACTIVE', 'ARCHIVED'])->default('DRAFT');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('maintenance_package_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('maintenance_package_id');
            $table->uuid('component_group_id')->nullable();
            $table->string('service_item');
            $table->string('recommended_part_reference')->nullable();
            $table->decimal('standard_labor_hours', 8, 2)->nullable();
            $table->uuid('checklist_template_id')->nullable();
            $table->timestamps();

            $table->foreign('maintenance_package_id')->references('id')->on('maintenance_packages')->cascadeOnDelete();
            $table->foreign('component_group_id')->references('id')->on('component_groups')->nullOnDelete();
            $table->foreign('checklist_template_id')->references('id')->on('inspection_templates')->nullOnDelete();
        });

        Schema::create('maintenance_intervals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('maintenance_package_id');
            $table->enum('trigger_type', ['ODOMETER', 'ENGINE_HOUR', 'CALENDAR_DAY', 'MONTH', 'COMBINATION', 'CONDITION_BASED']);
            $table->unsignedInteger('odometer_km')->nullable();
            $table->unsignedInteger('engine_hours')->nullable();
            $table->unsignedInteger('calendar_days')->nullable();
            $table->unsignedInteger('months')->nullable();
            $table->unsignedInteger('tolerance_km')->default(0);
            $table->unsignedInteger('tolerance_days')->default(0);
            $table->text('condition_notes')->nullable();
            $table->timestamps();

            $table->foreign('maintenance_package_id')->references('id')->on('maintenance_packages')->cascadeOnDelete();
        });

        Schema::create('vehicle_maintenance_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('vehicle_id');
            $table->uuid('maintenance_package_id');
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->date('effective_from')->default(now()->toDateString());
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('maintenance_package_id')->references('id')->on('maintenance_packages')->cascadeOnDelete();
            $table->unique(['vehicle_id', 'maintenance_package_id']);
        });

        Schema::create('maintenance_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('vehicle_id');
            $table->uuid('maintenance_package_id');
            $table->uuid('vehicle_maintenance_profile_id')->nullable();
            $table->date('next_due_date')->nullable();
            $table->unsignedInteger('next_due_odometer')->nullable();
            $table->unsignedInteger('next_due_engine_hour')->nullable();
            $table->unsignedInteger('tolerance_days')->default(0);
            $table->unsignedInteger('tolerance_odometer')->default(0);
            $table->string('source_policy')->nullable();
            $table->enum('status', ['UPCOMING', 'DUE_SOON', 'DUE', 'OVERDUE', 'SCHEDULED', 'COMPLETED'])->default('UPCOMING');
            $table->timestamp('last_completed_at')->nullable();
            $table->unsignedInteger('last_completed_odometer')->nullable();
            $table->uuid('last_completed_work_order_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('maintenance_package_id')->references('id')->on('maintenance_packages')->cascadeOnDelete();
            $table->foreign('vehicle_maintenance_profile_id')->references('id')->on('vehicle_maintenance_profiles')->nullOnDelete();
            // One open schedule per vehicle+package: due-calculation regenerates
            // it in place rather than accumulating duplicate schedule rows.
            $table->unique(['vehicle_id', 'maintenance_package_id'], 'maintenance_schedules_vehicle_package_unique');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_schedules');
        Schema::dropIfExists('vehicle_maintenance_profiles');
        Schema::dropIfExists('maintenance_intervals');
        Schema::dropIfExists('maintenance_package_items');
        Schema::dropIfExists('maintenance_packages');
    }
};
