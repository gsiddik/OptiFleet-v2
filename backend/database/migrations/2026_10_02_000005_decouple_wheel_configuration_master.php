<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrects 2026_10_02_000004: the wheel configuration Save was wrongly coupled to a vehicle
 * category (per-category versions replacing that category's positions, with installed-tire checks).
 * Wheel Configuration is a reusable master/template that no vehicle is linked to yet; assigning a
 * configuration to vehicles is a separate future feature.
 *
 * Audit before dropping: everything removed here was introduced by 000004 on the same unmerged
 * feature branch and is not referenced by any other feature —
 * - wheel_configuration_versions (category-scoped) — read/written only by the removed Save flow;
 * - the lifecycle columns added to the legacy wheel_configurations table (status, retired_at,
 *   introduced/retired_in_version_id, position_group, axle_in_group, side, wheel_index).
 * The legacy wheel_configurations rows themselves (and their original columns) are untouched.
 *
 * New model: wheel_configuration_masters → wheel_configuration_versions →
 * wheel_configuration_version_positions. Identity = vehicle_type + truck_configuration_type +
 * config_code (explicit fields, so 22.222 / +22.222 / -22.222 are distinct).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE wheel_configurations DROP CONSTRAINT IF EXISTS wheel_configurations_status_check');
        Schema::table('wheel_configurations', function (Blueprint $table) {
            $table->dropForeign(['introduced_in_version_id']);
            $table->dropForeign(['retired_in_version_id']);
            $table->dropIndex(['tenant_id', 'vehicle_category_id', 'status']);
            $table->dropColumn(['status', 'retired_at', 'introduced_in_version_id', 'retired_in_version_id', 'position_group', 'axle_in_group', 'side', 'wheel_index']);
        });
        Schema::drop('wheel_configuration_versions');

        Schema::create('wheel_configuration_masters', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->string('vehicle_type', 30);
            $table->string('truck_configuration_type', 30)->nullable();
            $table->string('config_code', 40); // of the current version; part of the identity
            $table->uuid('current_version_id')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->uuid('created_by')->nullable();
            $table->uuid('updated_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'vehicle_type']);
        });
        DB::statement("ALTER TABLE wheel_configuration_masters ADD CONSTRAINT wheel_configuration_masters_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))");
        DB::statement("ALTER TABLE wheel_configuration_masters ADD CONSTRAINT wheel_configuration_masters_truck_type_check CHECK ((vehicle_type = 'TRUCK' AND truck_configuration_type IN ('NON_TRAILER', 'TRAILER', 'SEMI_TRAILER')) OR (vehicle_type <> 'TRUCK' AND truck_configuration_type IS NULL))");
        // One active master per identity; COALESCE so a NULL truck type still collides.
        DB::statement("CREATE UNIQUE INDEX wheel_configuration_masters_identity ON wheel_configuration_masters (tenant_id, vehicle_type, COALESCE(truck_configuration_type, ''), config_code) WHERE status = 'ACTIVE'");

        Schema::create('wheel_configuration_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('wheel_configuration_master_id');
            $table->unsignedInteger('version_number');
            $table->string('config_code', 40);
            $table->jsonb('front_axles');
            $table->jsonb('rear_axles');
            $table->unsignedSmallInteger('spare_tires');
            $table->unsignedSmallInteger('total_axles');
            $table->unsignedSmallInteger('total_wheels');
            $table->string('status', 20)->default('ACTIVE');
            $table->jsonb('position_diff'); // vs the previous version: unchanged / added / removed codes
            $table->uuid('created_by')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('deactivated_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Version history is never deleted with its master.
            $table->foreign('wheel_configuration_master_id', 'wc_versions_master_fk')->references('id')->on('wheel_configuration_masters')->restrictOnDelete();
            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
            $table->unique(['wheel_configuration_master_id', 'version_number'], 'wc_versions_number_unique');
        });
        DB::statement("ALTER TABLE wheel_configuration_versions ADD CONSTRAINT wheel_configuration_versions_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))");
        DB::statement("CREATE UNIQUE INDEX wheel_configuration_versions_one_active ON wheel_configuration_versions (wheel_configuration_master_id) WHERE status = 'ACTIVE'");

        Schema::table('wheel_configuration_masters', function (Blueprint $table) {
            $table->foreign('current_version_id')->references('id')->on('wheel_configuration_versions')->nullOnDelete();
        });

        Schema::create('wheel_configuration_version_positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('wheel_configuration_version_id');
            $table->string('position_code', 20);
            $table->string('position_group', 10); // FRONT / REAR / SPARE
            $table->unsignedSmallInteger('axle_in_group')->nullable();
            $table->unsignedSmallInteger('axle_number')->nullable();
            $table->string('side', 1)->nullable(); // L / R
            $table->unsignedSmallInteger('wheel_index')->nullable();
            $table->string('label', 100);
            $table->unsignedSmallInteger('sequence');

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Explicit short names: the generated ones exceed Postgres' 63-char limit and collide.
            $table->foreign('wheel_configuration_version_id', 'wc_version_positions_version_fk')->references('id')->on('wheel_configuration_versions')->restrictOnDelete();
            $table->unique(['wheel_configuration_version_id', 'position_code'], 'wc_version_positions_code_unique');
        });
    }

    public function down(): void
    {
        Schema::table('wheel_configuration_masters', function (Blueprint $table) {
            $table->dropForeign(['current_version_id']);
        });
        Schema::dropIfExists('wheel_configuration_version_positions');
        Schema::dropIfExists('wheel_configuration_versions');
        Schema::dropIfExists('wheel_configuration_masters');

        // Restore the 000004 shape so its own down() still works.
        (include database_path('migrations/2026_10_02_000004_create_wheel_configuration_versions.php'))->up();
    }
};
