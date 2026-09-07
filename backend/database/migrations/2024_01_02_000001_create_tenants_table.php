<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('legal_name')->nullable();
            $table->string('industry')->nullable();
            $table->enum('status', ['DRAFT', 'ACTIVE', 'INACTIVE', 'SUSPENDED'])->default('DRAFT')->index();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tenant_users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('user_id');
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamp('joined_at')->useCurrent();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['tenant_id', 'user_id']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_users');
        Schema::dropIfExists('tenants');
    }
};
