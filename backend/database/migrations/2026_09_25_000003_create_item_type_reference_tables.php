<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Next Improvement Tenant Portal - Products": Tool Type, Equipment Type,
 * and Storage Requirement are all documented with an open-ended "dll."
 * (etc.) list rather than a fixed short enum, so — same reasoning as
 * Worker Type — they become real master-data tables (tenant-manageable,
 * `is_system` seeded defaults), not hardcoded enums. Same
 * tenant-null/is_system/partial-unique-index shape used throughout this
 * codebase (see component_groups, vehicle_categories, worker_types).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_types', function (Blueprint $table) {
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
        DB::statement('CREATE UNIQUE INDEX tool_types_system_code_unique ON tool_types (code) WHERE tenant_id IS NULL AND deleted_at IS NULL');

        Schema::create('equipment_types', function (Blueprint $table) {
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
        DB::statement('CREATE UNIQUE INDEX equipment_types_system_code_unique ON equipment_types (code) WHERE tenant_id IS NULL AND deleted_at IS NULL');

        Schema::create('storage_requirements', function (Blueprint $table) {
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
        DB::statement('CREATE UNIQUE INDEX storage_requirements_system_code_unique ON storage_requirements (code) WHERE tenant_id IS NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('storage_requirements');
        Schema::dropIfExists('equipment_types');
        Schema::dropIfExists('tool_types');
    }
};
