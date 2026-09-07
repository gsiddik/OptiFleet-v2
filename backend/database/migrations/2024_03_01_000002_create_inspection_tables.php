<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inspection_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('vehicle_category_id')->nullable();
            $table->enum('inspection_type', ['PRE_TRIP', 'POST_TRIP', 'PERIODIC', 'WORKSHOP', 'MAINTENANCE']);
            $table->string('name');
            $table->text('description')->nullable();
            $table->enum('status', ['DRAFT', 'ACTIVE', 'ARCHIVED'])->default('DRAFT');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('vehicle_category_id')->references('id')->on('vehicle_categories')->nullOnDelete();
            $table->index(['tenant_id', 'inspection_type', 'status']);
        });

        Schema::create('inspection_template_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('inspection_template_id');
            $table->uuid('component_group_id')->nullable();
            $table->string('item_text');
            $table->enum('input_type', ['CHECKBOX', 'PASS_FAIL', 'TEXT', 'NUMBER', 'SELECT', 'PHOTO']);
            $table->json('options')->nullable();
            $table->boolean('required')->default(true);
            $table->unsignedInteger('sequence')->default(0);
            $table->string('threshold')->nullable();
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();

            $table->foreign('inspection_template_id')->references('id')->on('inspection_templates')->cascadeOnDelete();
            $table->foreign('component_group_id')->references('id')->on('component_groups')->nullOnDelete();
            $table->index(['inspection_template_id', 'sequence']);
        });

        Schema::create('inspections', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('branch_id');
            $table->uuid('workshop_id')->nullable();
            $table->uuid('vehicle_id');
            $table->uuid('inspection_template_id');
            $table->enum('inspection_type', ['PRE_TRIP', 'POST_TRIP', 'PERIODIC', 'WORKSHOP', 'MAINTENANCE']);
            $table->enum('status', ['CREATED', 'ASSIGNED', 'STARTED', 'SUBMITTED', 'PASSED', 'WARNING', 'FAILED'])->default('CREATED');
            $table->uuid('assigned_to')->nullable();
            $table->decimal('odometer_at_inspection', 12, 2)->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->uuid('created_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('workshop_id')->references('id')->on('workshops')->nullOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('inspection_template_id')->references('id')->on('inspection_templates')->restrictOnDelete();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'vehicle_id']);
        });

        Schema::create('inspection_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('inspection_id');
            $table->uuid('inspection_template_item_id');
            $table->text('value_text')->nullable();
            $table->decimal('value_number', 12, 2)->nullable();
            $table->boolean('value_bool')->nullable();
            $table->boolean('passed')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamps();

            $table->foreign('inspection_id')->references('id')->on('inspections')->cascadeOnDelete();
            $table->foreign('inspection_template_item_id')->references('id')->on('inspection_template_items')->restrictOnDelete();
            $table->unique(['inspection_id', 'inspection_template_item_id']);
        });

        Schema::create('inspection_findings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('inspection_id');
            $table->uuid('vehicle_id');
            $table->uuid('component_group_id')->nullable();
            $table->uuid('category_id')->nullable(); // extension point: future finding-category master
            $table->enum('severity', ['INFO', 'LOW', 'MEDIUM', 'HIGH', 'CRITICAL']);
            $table->text('description');
            $table->string('evidence')->nullable();
            $table->text('recommended_action')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('inspection_id')->references('id')->on('inspections')->cascadeOnDelete();
            $table->foreign('vehicle_id')->references('id')->on('vehicles')->cascadeOnDelete();
            $table->foreign('component_group_id')->references('id')->on('component_groups')->nullOnDelete();
            $table->index(['tenant_id', 'vehicle_id']);
            $table->index(['tenant_id', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inspection_findings');
        Schema::dropIfExists('inspection_results');
        Schema::dropIfExists('inspections');
        Schema::dropIfExists('inspection_template_items');
        Schema::dropIfExists('inspection_templates');
    }
};
