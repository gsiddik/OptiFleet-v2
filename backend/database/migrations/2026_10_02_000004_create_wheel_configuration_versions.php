<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Production Save for "New Wheels Configuration": configuration versioning + position-set diffing.
 *
 * - wheel_configuration_versions: one row per saved configuration of a (tenant, vehicle category);
 *   exactly one ACTIVE per pair, older ones SUPERSEDED (never deleted). The Truck Configuration
 *   Type is stored explicitly next to the human-readable Config Code.
 * - wheel_configurations (positions) gains a lifecycle: ACTIVE / RETIRED. A position that leaves
 *   the configuration is retired, never hard-deleted, because tire_installations / tire_rotations
 *   reference positions by code. Existing rows default to ACTIVE, so behavior is unchanged until a
 *   tenant saves its first versioned configuration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wheel_configuration_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('vehicle_category_id');
            $table->unsignedInteger('version_number');
            $table->string('vehicle_type', 30);
            $table->string('truck_configuration_type', 30)->nullable();
            $table->string('config_code', 40);
            $table->jsonb('front_axles');
            $table->jsonb('rear_axles');
            $table->unsignedSmallInteger('spare_tires');
            $table->unsignedSmallInteger('total_axles');
            $table->unsignedSmallInteger('total_wheels');
            $table->string('status', 20)->default('ACTIVE');
            $table->jsonb('position_diff');
            $table->uuid('created_by')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('superseded_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // History must survive: a category with saved versions cannot be hard-deleted.
            $table->foreign('vehicle_category_id')->references('id')->on('vehicle_categories')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['tenant_id', 'vehicle_category_id', 'version_number']);
            $table->index(['tenant_id', 'vehicle_category_id', 'status']);
        });

        DB::statement("ALTER TABLE wheel_configuration_versions ADD CONSTRAINT wheel_configuration_versions_status_check CHECK (status IN ('ACTIVE', 'SUPERSEDED'))");
        DB::statement("ALTER TABLE wheel_configuration_versions ADD CONSTRAINT wheel_configuration_versions_truck_type_check CHECK ((vehicle_type = 'TRUCK' AND truck_configuration_type IN ('NON_TRAILER', 'TRAILER', 'SEMI_TRAILER')) OR (vehicle_type <> 'TRUCK' AND truck_configuration_type IS NULL))");
        DB::statement("CREATE UNIQUE INDEX wheel_configuration_versions_one_active ON wheel_configuration_versions (tenant_id, vehicle_category_id) WHERE status = 'ACTIVE'");

        Schema::table('wheel_configurations', function (Blueprint $table) {
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamp('retired_at')->nullable();
            $table->uuid('introduced_in_version_id')->nullable();
            $table->uuid('retired_in_version_id')->nullable();
            $table->string('position_group', 10)->nullable(); // FRONT / REAR / SPARE
            $table->unsignedSmallInteger('axle_in_group')->nullable();
            $table->string('side', 1)->nullable(); // L / R
            $table->unsignedSmallInteger('wheel_index')->nullable();

            $table->foreign('introduced_in_version_id')->references('id')->on('wheel_configuration_versions')->nullOnDelete();
            $table->foreign('retired_in_version_id')->references('id')->on('wheel_configuration_versions')->nullOnDelete();
            $table->index(['tenant_id', 'vehicle_category_id', 'status']);
        });

        DB::statement("ALTER TABLE wheel_configurations ADD CONSTRAINT wheel_configurations_status_check CHECK (status IN ('ACTIVE', 'RETIRED'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE wheel_configurations DROP CONSTRAINT IF EXISTS wheel_configurations_status_check');
        Schema::table('wheel_configurations', function (Blueprint $table) {
            $table->dropForeign(['introduced_in_version_id']);
            $table->dropForeign(['retired_in_version_id']);
            $table->dropIndex(['tenant_id', 'vehicle_category_id', 'status']);
            $table->dropColumn(['status', 'retired_at', 'introduced_in_version_id', 'retired_in_version_id', 'position_group', 'axle_in_group', 'side', 'wheel_index']);
        });
        Schema::dropIfExists('wheel_configuration_versions');
    }
};
