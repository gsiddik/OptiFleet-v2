<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name'); // resource.action — may exist once per scope (e.g. user.view for platform AND tenant)
            $table->string('group');
            $table->enum('scope', ['platform', 'tenant'])->default('tenant')->index();
            $table->string('description')->nullable();
            $table->timestamps();

            $table->unique(['name', 'scope']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('name');
            $table->enum('scope', ['platform', 'tenant'])->default('tenant')->index();
            $table->boolean('is_system')->default(false);
            $table->string('description')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'name']);
            $table->index(['tenant_id', 'scope']);
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->timestamps();

            $table->primary(['role_id', 'permission_id']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('tenant_id')->nullable(); // null = platform-scope assignment
            $table->uuid('role_id');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->unique(['user_id', 'tenant_id', 'role_id'], 'role_assignments_unique');
            $table->index(['tenant_id', 'user_id']);
        });

        Schema::create('data_scope_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('tenant_id');
            $table->enum('scope_type', ['TENANT', 'BRANCH', 'WORKSHOP', 'WAREHOUSE', 'OWN'])->index();
            $table->uuid('scope_resource_id')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['user_id', 'tenant_id', 'scope_type', 'scope_resource_id'], 'data_scope_unique');
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('data_scope_assignments');
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
