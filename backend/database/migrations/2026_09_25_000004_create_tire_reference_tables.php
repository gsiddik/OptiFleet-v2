<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Next Improvement Tenant Portal - Products" Section 16-17: Tire's
 * derived values (Max Load, Max Speed, Load Range, TRA Profile, Purpose)
 * must come from authoritative master/reference data, never be
 * hand-typed — these are the standardized ISO/TRA lookup tables that
 * back them. One `tire_load_indices` table serves BOTH the Single and
 * Dual Load Index dropdowns (a standardized load-index number defines
 * both a single-mounting and a dual-mounting max load simultaneously —
 * not two separate concepts). Star Rating is scoped per TRA Code (its
 * available options and the derived Purpose both depend on which TRA
 * Code was picked first), hence the child `tire_tra_star_ratings` table
 * rather than a flat, TRA-Code-independent Star Rating list.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tire_load_indices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('code'); // e.g. "92", "152"
            $table->decimal('max_load_single_kg', 8, 2)->nullable();
            $table->decimal('max_load_dual_kg', 8, 2)->nullable();
            $table->boolean('is_system')->default(false);
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
        DB::statement('CREATE UNIQUE INDEX tire_load_indices_system_code_unique ON tire_load_indices (code) WHERE tenant_id IS NULL AND deleted_at IS NULL');

        Schema::create('tire_speed_ratings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('code'); // e.g. "T", "M"
            $table->decimal('max_speed_kmh', 6, 2)->nullable();
            $table->boolean('is_system')->default(false);
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
        DB::statement('CREATE UNIQUE INDEX tire_speed_ratings_system_code_unique ON tire_speed_ratings (code) WHERE tenant_id IS NULL AND deleted_at IS NULL');

        Schema::create('tire_ply_ratings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('code'); // e.g. "16 PR"
            $table->string('load_range')->nullable(); // e.g. "H"
            $table->boolean('is_system')->default(false);
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
        DB::statement('CREATE UNIQUE INDEX tire_ply_ratings_system_code_unique ON tire_ply_ratings (code) WHERE tenant_id IS NULL AND deleted_at IS NULL');

        Schema::create('tire_tra_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('code'); // e.g. "G2"
            $table->string('profile')->nullable(); // TRA Profile, e.g. "G"
            $table->boolean('is_system')->default(false);
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
        DB::statement('CREATE UNIQUE INDEX tire_tra_codes_system_code_unique ON tire_tra_codes (code) WHERE tenant_id IS NULL AND deleted_at IS NULL');

        Schema::create('tire_tra_star_ratings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('tra_code_id');
            $table->string('star_rating'); // e.g. "2★"
            $table->string('purpose')->nullable(); // derived Purpose for this TRA Code + Star Rating combination
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tra_code_id')->references('id')->on('tire_tra_codes')->cascadeOnDelete();
            $table->unique(['tra_code_id', 'star_rating']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tire_tra_star_ratings');
        Schema::dropIfExists('tire_tra_codes');
        Schema::dropIfExists('tire_ply_ratings');
        Schema::dropIfExists('tire_speed_ratings');
        Schema::dropIfExists('tire_load_indices');
    }
};
