<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Section 12: manual schedule creation picks a Schedule Start Date. The
 * existing maintenance_schedules row model is one continuously-upserted row
 * per (vehicle, package) — never a calendar of discrete occurrences — so a
 * "new schedule" for a vehicle+package that already has an open row updates
 * that same row rather than inserting a second one; per-package date
 * collision is therefore not meaningful (there is only ever one date field
 * for that pairing). What the document's "not already scheduled for the
 * selected vehicle" check actually needs to prevent is two schedules for the
 * SAME VEHICLE landing on the same date — enforced here as a partial unique
 * index scoped to the vehicle, not the package, since nothing in the
 * existing schema states a vehicle may only ever have one package's
 * schedule at a time. This is a documented design decision, not a
 * pre-existing behavior guarantee.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->date('schedule_start_date')->nullable()->after('package_snapshot');
        });

        DB::statement(
            'CREATE UNIQUE INDEX maintenance_schedules_vehicle_start_date_unique '.
            'ON maintenance_schedules (vehicle_id, schedule_start_date) WHERE schedule_start_date IS NOT NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS maintenance_schedules_vehicle_start_date_unique');

        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->dropColumn('schedule_start_date');
        });
    }
};
