<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * G-13: Vehicle.brand/model were always free-text strings with no shared
 * master data behind them. This adds VehicleBrand/VehicleModel (mirroring
 * the existing VehicleCategory master-data shape) plus nullable FK columns
 * on vehicles — the existing free-text brand/model columns are left
 * untouched for backward compatibility; the new columns are purely
 * additive/optional.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_brands', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
        });
        DB::statement('CREATE UNIQUE INDEX vehicle_brands_system_code_unique ON vehicle_brands (code) WHERE tenant_id IS NULL AND deleted_at IS NULL');

        Schema::create('vehicle_models', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('vehicle_brand_id');
            $table->string('code');
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('vehicle_brand_id')->references('id')->on('vehicle_brands')->restrictOnDelete();
            $table->unique(['vehicle_brand_id', 'code']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->uuid('vehicle_brand_id')->nullable()->after('brand');
            $table->uuid('vehicle_model_id')->nullable()->after('model');

            $table->foreign('vehicle_brand_id')->references('id')->on('vehicle_brands')->restrictOnDelete();
            $table->foreign('vehicle_model_id')->references('id')->on('vehicle_models')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropForeign(['vehicle_brand_id']);
            $table->dropForeign(['vehicle_model_id']);
            $table->dropColumn(['vehicle_brand_id', 'vehicle_model_id']);
        });
        Schema::dropIfExists('vehicle_models');
        Schema::dropIfExists('vehicle_brands');
    }
};
