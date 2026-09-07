<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
        DB::statement('CREATE UNIQUE INDEX vehicle_categories_system_code_unique ON vehicle_categories (code) WHERE tenant_id IS NULL AND deleted_at IS NULL');

        Schema::create('component_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->uuid('parent_id')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'parent_id']);
        });
        DB::statement('CREATE UNIQUE INDEX component_groups_system_code_unique ON component_groups (code) WHERE tenant_id IS NULL AND deleted_at IS NULL');

        Schema::table('component_groups', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('component_groups')->nullOnDelete();
        });

        Schema::create('vehicle_category_component_groups', function (Blueprint $table) {
            $table->uuid('vehicle_category_id');
            $table->uuid('component_group_id');
            $table->timestamps();

            $table->primary(['vehicle_category_id', 'component_group_id'], 'vc_cg_primary');
            $table->foreign('vehicle_category_id')->references('id')->on('vehicle_categories')->cascadeOnDelete();
            $table->foreign('component_group_id')->references('id')->on('component_groups')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_category_component_groups');
        Schema::dropIfExists('component_groups');
        Schema::dropIfExists('vehicle_categories');
    }
};
