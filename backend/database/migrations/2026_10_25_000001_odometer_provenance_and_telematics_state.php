<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Odometer provenance and telematics state (audit A10). Additive and backward compatible: nothing existing is dropped or rewritten,
 * every new column is nullable or has a default, and existing rows get only values that follow exactly from what they already say.
 *
 *  - vehicles.odometer_source / odometer_measured_at / odometer_reading_id: where the current odometer came from. NULL for a
 *    vehicle whose odometer was recorded before this migration: the origin is unknown and is shown as unknown, never guessed.
 *  - vehicle_odometer_readings becomes the odometer ledger for every source (telematics, manual entry, inspection, work order, tire
 *    operation, vehicle release): measured time (`recorded_at`), receive time, unit, what was done with the value (`disposition`),
 *    why it was held back (`anomaly_code`) and who reviewed it. device_ref is optional (a manual entry has no device).
 *  - vehicle_telematics_links: review state of a device (reset, device replacement, implausible jump), the per-kind baseline the
 *    next reading is compared with, and a rebase offset for device odometers.
 *  - integration_sync_cursors: outcome of the last attempt (status, error code, failures in a row, counts), so a silent or failing
 *    feed is visible instead of looking like "no data".
 *  - tenants.telematics_activated_at: the tenant has switched the telematics feed on. Tenants whose feed has already synced
 *    successfully before this migration are marked activated at that time, so they keep receiving readings.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicles', function (Blueprint $table) {
            $table->string('odometer_source', 40)->nullable();
            $table->timestampTz('odometer_measured_at')->nullable();
            $table->uuid('odometer_reading_id')->nullable();
        });

        Schema::table('vehicle_odometer_readings', function (Blueprint $table) {
            $table->string('device_ref')->nullable()->change();
            $table->string('odometer_kind', 20)->nullable()->change();

            $table->string('origin', 40)->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->string('unit', 8)->default('km');
            $table->string('disposition', 16)->default('stored');
            $table->string('anomaly_code', 32)->nullable();
            $table->string('review_status', 16)->nullable();
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->foreignUuid('entered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('note')->nullable();
            $table->string('reference_type', 40)->nullable();
            $table->uuid('reference_id')->nullable();
        });

        // What the rows already say, no more: received = when the row was written; disposition from `applied` / `effective_km`.
        DB::statement('UPDATE vehicle_odometer_readings SET received_at = created_at WHERE received_at IS NULL');
        DB::statement("UPDATE vehicle_odometer_readings SET disposition = CASE WHEN applied THEN 'applied' WHEN effective_km IS NULL THEN 'held' ELSE 'stored' END");
        DB::statement("ALTER TABLE vehicle_odometer_readings ADD CONSTRAINT vehicle_odometer_readings_disposition_check CHECK (disposition IN ('applied','stored','held','late','quarantined','correction'))");
        DB::statement("ALTER TABLE vehicle_odometer_readings ADD CONSTRAINT vehicle_odometer_readings_review_status_check CHECK (review_status IS NULL OR review_status IN ('pending','rebased','accepted','dismissed'))");
        DB::statement("ALTER TABLE vehicle_odometer_readings ADD CONSTRAINT vehicle_odometer_readings_unit_check CHECK (unit = 'km')");
        DB::statement("CREATE INDEX vehicle_odometer_readings_pending_review ON vehicle_odometer_readings (tenant_id, vehicle_id) WHERE review_status = 'pending'");

        Schema::table('vehicle_telematics_links', function (Blueprint $table) {
            $table->string('status', 16)->default('active');
            $table->string('review_reason', 32)->nullable();
            $table->timestampTz('review_opened_at')->nullable();
            $table->uuid('review_reading_id')->nullable();
            // Fleet odometer = device odometer + rebase offset (device-odometer readings only; GPS distance has odometer_offset_km).
            $table->decimal('rebase_offset_km', 12, 2)->nullable();
            // Per kind: {"DEVICE_ODOMETER": {"km": "123.45", "at": "2026-10-17T10:00:00Z"}, "GPS_DISTANCE": {...}}
            $table->json('baselines')->nullable();
            $table->timestampTz('last_measured_at')->nullable();
            $table->timestampTz('last_received_at')->nullable();
        });
        DB::statement("ALTER TABLE vehicle_telematics_links ADD CONSTRAINT vehicle_telematics_links_status_check CHECK (status IN ('active','review'))");

        Schema::table('integration_sync_cursors', function (Blueprint $table) {
            $table->timestampTz('last_attempted_at')->nullable();
            $table->string('last_status', 32)->nullable();
            $table->string('last_error_code', 64)->nullable();
            $table->unsignedInteger('consecutive_failures')->default(0);
            $table->json('last_stats')->nullable();
            $table->timestampTz('last_reading_received_at')->nullable();
        });

        Schema::table('tenants', function (Blueprint $table) {
            $table->timestampTz('telematics_activated_at')->nullable();
            $table->foreignUuid('telematics_activated_by')->nullable()->constrained('users')->nullOnDelete();
        });
        DB::statement(<<<'SQL'
            UPDATE tenants SET telematics_activated_at = c.first_sync
            FROM (
                SELECT tenant_id, MIN(created_at) AS first_sync
                FROM integration_sync_cursors
                WHERE stream = 'optinexus.odometer' AND last_synced_at IS NOT NULL
                GROUP BY tenant_id
            ) c
            WHERE c.tenant_id = tenants.id AND tenants.telematics_activated_at IS NULL
        SQL);
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('telematics_activated_by');
            $table->dropColumn('telematics_activated_at');
        });

        Schema::table('integration_sync_cursors', function (Blueprint $table) {
            $table->dropColumn(['last_attempted_at', 'last_status', 'last_error_code', 'consecutive_failures', 'last_stats', 'last_reading_received_at']);
        });

        DB::statement('ALTER TABLE vehicle_telematics_links DROP CONSTRAINT IF EXISTS vehicle_telematics_links_status_check');
        Schema::table('vehicle_telematics_links', function (Blueprint $table) {
            $table->dropColumn(['status', 'review_reason', 'review_opened_at', 'review_reading_id', 'rebase_offset_km', 'baselines', 'last_measured_at', 'last_received_at']);
        });

        DB::statement('DROP INDEX IF EXISTS vehicle_odometer_readings_pending_review');
        DB::statement('ALTER TABLE vehicle_odometer_readings DROP CONSTRAINT IF EXISTS vehicle_odometer_readings_unit_check');
        DB::statement('ALTER TABLE vehicle_odometer_readings DROP CONSTRAINT IF EXISTS vehicle_odometer_readings_review_status_check');
        DB::statement('ALTER TABLE vehicle_odometer_readings DROP CONSTRAINT IF EXISTS vehicle_odometer_readings_disposition_check');
        Schema::table('vehicle_odometer_readings', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropConstrainedForeignId('entered_by');
            $table->dropColumn(['origin', 'received_at', 'unit', 'disposition', 'anomaly_code', 'review_status', 'reviewed_at', 'review_note', 'note', 'reference_type', 'reference_id']);
        });

        Schema::table('vehicles', function (Blueprint $table) {
            $table->dropColumn(['odometer_source', 'odometer_measured_at', 'odometer_reading_id']);
        });
    }
};
