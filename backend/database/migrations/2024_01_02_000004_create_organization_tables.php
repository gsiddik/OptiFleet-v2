<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('code');
            $table->string('name');
            $table->string('address')->nullable();
            $table->string('province')->nullable();
            $table->string('city')->nullable();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('pic')->nullable();
            $table->string('operational_hours')->nullable();
            $table->enum('status', ['DRAFT', 'ACTIVE', 'INACTIVE', 'CLOSED'])->default('DRAFT');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('workshops', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('branch_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->enum('workshop_type', ['INTERNAL', 'SATELLITE', 'MOBILE'])->default('INTERNAL');
            $table->string('address')->nullable();
            $table->string('pic')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->unsignedInteger('number_of_service_bays')->nullable();
            $table->string('operational_hours')->nullable();
            $table->enum('status', ['DRAFT', 'ACTIVE', 'INACTIVE', 'CLOSED'])->default('DRAFT');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['branch_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('branches')->nullOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['id', 'tenant_id']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'branch_id']);
        });

        Schema::create('warehouses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('branch_id')->nullable();
            $table->uuid('workshop_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->enum('warehouse_type', ['CENTRAL', 'BRANCH', 'WORKSHOP', 'TIRE', 'CONSUMABLE', 'SCRAP', 'QUARANTINE'])->default('BRANCH');
            $table->string('address')->nullable();
            $table->string('pic')->nullable();
            $table->enum('status', ['DRAFT', 'ACTIVE', 'INACTIVE', 'CLOSED'])->default('DRAFT');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign(['branch_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('branches')->nullOnDelete();
            $table->foreign(['workshop_id', 'tenant_id'])->references(['id', 'tenant_id'])->on('workshops')->nullOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'branch_id']);
            $table->index(['tenant_id', 'workshop_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
        Schema::dropIfExists('workshops');
        Schema::dropIfExists('branches');
    }
};
