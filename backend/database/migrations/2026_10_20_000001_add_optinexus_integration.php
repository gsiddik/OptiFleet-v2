<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * OptiNexus platform integration (additive, backward compatible: every new
 * column is nullable, so tenants and users that are not linked keep working
 * exactly as before).
 *
 *  - tenants.optinexus_tenant_id / users.optinexus_subject link local records
 *    to the OptiNexus identity (SSO).
 *  - vehicle_odometer_readings keeps every telematics reading received through
 *    the OptiNexus API Gateway, whether or not it moved the odometer.
 *  - vehicle_telematics_links holds the per-vehicle calibration offset (set manually by an admin).
 *  - integration_sync_cursors remembers how far each tenant's feed was read.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->uuid('optinexus_tenant_id')->nullable()->unique();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->uuid('optinexus_subject')->nullable()->unique();
        });

        Schema::create('vehicle_telematics_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('source', 40);
            $table->string('device_ref');
            // Fleet odometer = GPS distance + offset. NULL until an admin calibrates the vehicle:
            // GPS-distance readings are held (stored, not applied) while it is NULL.
            $table->decimal('odometer_offset_km', 12, 2)->nullable();
            $table->foreignUuid('calibrated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('calibrated_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'vehicle_id', 'source', 'device_ref'], 'vehicle_telematics_links_unique');
        });

        Schema::create('vehicle_odometer_readings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignUuid('vehicle_id')->constrained('vehicles')->cascadeOnDelete();
            $table->string('source', 40);
            $table->uuid('source_reading_id');
            $table->string('device_ref');
            $table->string('odometer_kind', 20);
            $table->decimal('reported_km', 12, 2);
            $table->decimal('effective_km', 12, 2)->nullable();
            $table->decimal('previous_odometer', 12, 2);
            $table->boolean('applied')->default(false);
            $table->timestamp('recorded_at');
            $table->timestamps();

            $table->unique(['tenant_id', 'source', 'source_reading_id'], 'vehicle_odometer_readings_source_unique');
            $table->index(['tenant_id', 'vehicle_id', 'recorded_at']);
        });

        Schema::create('integration_sync_cursors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('stream', 60);
            $table->unsignedBigInteger('cursor')->default(0);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'stream']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_sync_cursors');
        Schema::dropIfExists('vehicle_odometer_readings');
        Schema::dropIfExists('vehicle_telematics_links');
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('optinexus_subject'));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn('optinexus_tenant_id'));
    }
};
