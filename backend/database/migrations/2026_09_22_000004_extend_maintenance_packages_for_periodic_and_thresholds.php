<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Section 10-11: PERIODIC is a new maintenance_type alongside the existing
 * five values — additive only, so historical PREVENTIVE/CORRECTIVE/
 * BREAKDOWN/INSPECTION/CAMPAIGN packages are unaffected. The threshold
 * columns are a parallel, simpler mechanism for the new PREVENTIVE/
 * PERIODIC package-creation workflow; the existing maintenance_intervals
 * table is left untouched for legacy package types and any package that
 * still uses it. package_snapshot freezes what a schedule was created
 * against, the same historical-integrity pattern as inspections'
 * template_snapshot.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE maintenance_packages DROP CONSTRAINT maintenance_packages_maintenance_type_check');
        DB::statement(
            'ALTER TABLE maintenance_packages ADD CONSTRAINT maintenance_packages_maintenance_type_check '.
            'CHECK (maintenance_type::text = ANY (ARRAY['.
            "'PREVENTIVE','CORRECTIVE','BREAKDOWN','INSPECTION','CAMPAIGN','PERIODIC'".
            ']::character varying[]))'
        );

        Schema::table('maintenance_packages', function (Blueprint $table) {
            // Reuses maintenance_intervals.trigger_type's existing vocabulary
            // (CALENDAR_DAY, ODOMETER) rather than the document's literal
            // DAYS/KM wording, per its own instruction to prefer whatever
            // naming the codebase already established.
            $table->enum('period_by', ['CALENDAR_DAY', 'MONTH', 'ODOMETER', 'ENGINE_HOUR'])->nullable()->after('maintenance_type');
            $table->unsignedInteger('threshold_days')->nullable()->after('period_by');
            $table->unsignedInteger('threshold_month')->nullable()->after('threshold_days');
            $table->unsignedInteger('threshold_km')->nullable()->after('threshold_month');
            $table->unsignedInteger('threshold_engine_hour')->nullable()->after('threshold_km');
            $table->unsignedInteger('schedule_period')->nullable()->after('threshold_engine_hour');
        });

        Schema::create('maintenance_package_component_groups', function (Blueprint $table) {
            $table->uuid('maintenance_package_id');
            $table->uuid('component_group_id');
            $table->timestamps();

            $table->primary(['maintenance_package_id', 'component_group_id'], 'mp_cg_primary');
            $table->foreign('maintenance_package_id')->references('id')->on('maintenance_packages')->cascadeOnDelete();
            $table->foreign('component_group_id')->references('id')->on('component_groups')->cascadeOnDelete();
        });

        // Deterministic backfill: an existing package's already-linked items'
        // component groups become its initial checkbox selection. No
        // invented data — a package with no component-group-linked items
        // simply starts with none selected.
        DB::statement(
            'INSERT INTO maintenance_package_component_groups (maintenance_package_id, component_group_id, created_at, updated_at)
             SELECT DISTINCT maintenance_package_id, component_group_id, NOW(), NOW()
             FROM maintenance_package_items
             WHERE component_group_id IS NOT NULL'
        );

        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->json('package_snapshot')->nullable()->after('maintenance_package_id');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_schedules', function (Blueprint $table) {
            $table->dropColumn('package_snapshot');
        });

        Schema::dropIfExists('maintenance_package_component_groups');

        Schema::table('maintenance_packages', function (Blueprint $table) {
            $table->dropColumn(['period_by', 'threshold_days', 'threshold_month', 'threshold_km', 'threshold_engine_hour', 'schedule_period']);
        });

        DB::statement('ALTER TABLE maintenance_packages DROP CONSTRAINT maintenance_packages_maintenance_type_check');
        DB::statement(
            'ALTER TABLE maintenance_packages ADD CONSTRAINT maintenance_packages_maintenance_type_check '.
            'CHECK (maintenance_type::text = ANY (ARRAY['.
            "'PREVENTIVE','CORRECTIVE','BREAKDOWN','INSPECTION','CAMPAIGN'".
            ']::character varying[]))'
        );
    }
};
