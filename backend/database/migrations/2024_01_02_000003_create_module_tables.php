<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('category');
            $table->boolean('is_core')->default(false);
            $table->boolean('is_sellable')->default(true);
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE')->index();
            $table->timestamps();
        });

        Schema::create('module_dependencies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('module_id');
            $table->uuid('depends_on_module_id');
            $table->timestamps();

            $table->foreign('module_id')->references('id')->on('modules')->cascadeOnDelete();
            $table->foreign('depends_on_module_id')->references('id')->on('modules')->cascadeOnDelete();
            $table->unique(['module_id', 'depends_on_module_id']);
        });

        Schema::create('tenant_module_entitlements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('module_id');
            $table->boolean('active')->default(true);
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->string('source')->default('manual'); // seed, manual, bundle
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('module_id')->references('id')->on('modules')->cascadeOnDelete();
            $table->unique(['tenant_id', 'module_id']);
            $table->index(['tenant_id', 'active']);
        });

        Schema::create('tenant_capacity_limits', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->enum('resource_type', ['vehicle', 'user', 'branch', 'workshop', 'warehouse']);
            $table->unsignedInteger('max_count');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'resource_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_capacity_limits');
        Schema::dropIfExists('tenant_module_entitlements');
        Schema::dropIfExists('module_dependencies');
        Schema::dropIfExists('modules');
    }
};
