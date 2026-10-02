<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Vehicle → Wheel Configuration mapping (assignment), version-aware and historical.
 *
 * One row per assignment period: a vehicle is mapped to a specific configuration VERSION (editing
 * the master later creates a new version but never moves mapped vehicles). Ending a mapping keeps
 * the row (status ENDED + who/when/why), so the table is also the mapping history. At most one
 * ACTIVE row per vehicle (partial unique index).
 *
 * Also adds the tenant permission wheel_configuration.map_vehicle, granted to every role that can
 * already manage wheel configurations (tire.manage), so no tenant loses the ability.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vehicle_wheel_configuration_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('vehicle_id');
            $table->uuid('wheel_configuration_master_id');
            $table->uuid('wheel_configuration_version_id');
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamp('mapped_at');
            $table->uuid('mapped_by')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->uuid('ended_by')->nullable();
            $table->string('end_reason', 30)->nullable();
            $table->uuid('previous_mapping_id')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            // Mapping history must survive: neither the vehicle nor the configuration can be
            // hard-deleted while a mapping (active or historical) references it.
            $table->foreign('vehicle_id', 'vwcm_vehicle_fk')->references('id')->on('vehicles')->restrictOnDelete();
            $table->foreign('wheel_configuration_master_id', 'vwcm_master_fk')->references('id')->on('wheel_configuration_masters')->restrictOnDelete();
            $table->foreign('wheel_configuration_version_id', 'vwcm_version_fk')->references('id')->on('wheel_configuration_versions')->restrictOnDelete();
            $table->foreign('mapped_by', 'vwcm_mapped_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->foreign('ended_by', 'vwcm_ended_by_fk')->references('id')->on('users')->nullOnDelete();
            $table->index(['tenant_id', 'wheel_configuration_master_id', 'status'], 'vwcm_master_status_idx');
            $table->index(['vehicle_id', 'mapped_at'], 'vwcm_vehicle_history_idx');
        });
        Schema::table('vehicle_wheel_configuration_mappings', function (Blueprint $table) {
            $table->foreign('previous_mapping_id', 'vwcm_previous_fk')->references('id')->on('vehicle_wheel_configuration_mappings')->nullOnDelete();
        });
        DB::statement("ALTER TABLE vehicle_wheel_configuration_mappings ADD CONSTRAINT vwcm_status_check CHECK (status IN ('ACTIVE', 'ENDED'))");
        DB::statement("ALTER TABLE vehicle_wheel_configuration_mappings ADD CONSTRAINT vwcm_end_check CHECK ((status = 'ACTIVE' AND ended_at IS NULL AND end_reason IS NULL) OR (status = 'ENDED' AND ended_at IS NOT NULL AND end_reason IN ('UNMAPPED', 'VERSION_UPDATED')))");
        DB::statement("CREATE UNIQUE INDEX vwcm_one_active_per_vehicle ON vehicle_wheel_configuration_mappings (vehicle_id) WHERE status = 'ACTIVE'");

        $id = DB::table('permissions')->where('name', 'wheel_configuration.map_vehicle')->where('scope', 'tenant')->value('id');
        if ($id === null) {
            $id = (string) Str::uuid();
            DB::table('permissions')->insert([
                'id' => $id, 'name' => 'wheel_configuration.map_vehicle', 'group' => 'wheel_configuration', 'scope' => 'tenant',
                'description' => 'Map vehicles to wheel configurations',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $source = DB::table('permissions')->where('name', 'tire.manage')->where('scope', 'tenant')->value('id');
        if ($source !== null) {
            $granted = DB::table('role_permissions')->where('permission_id', $id)->pluck('role_id')->all();
            $rows = DB::table('role_permissions')->where('permission_id', $source)->distinct()->pluck('role_id')
                ->reject(fn ($roleId) => in_array($roleId, $granted, true))
                ->map(fn ($roleId) => ['role_id' => $roleId, 'permission_id' => $id, 'created_at' => now(), 'updated_at' => now()])
                ->values()->all();
            if ($rows !== []) {
                DB::table('role_permissions')->insert($rows);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('vehicle_wheel_configuration_mappings');
        $id = DB::table('permissions')->where('name', 'wheel_configuration.map_vehicle')->value('id');
        if ($id !== null) {
            DB::table('role_permissions')->where('permission_id', $id)->delete();
            DB::table('permissions')->where('id', $id)->delete();
        }
    }
};
