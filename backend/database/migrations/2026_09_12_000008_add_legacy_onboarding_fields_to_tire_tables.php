<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-23: Legacy Tire Onboarding. install() previously hardcoded
 * 'installed_at' => now() with no way to backdate it and no baseline
 * inspection — the only way to register a tire already mounted before
 * OptiFleet was adopted stamped a false "installed right now" date.
 *
 * installation_date_source records whether the (possibly backdated)
 * installed_at is KNOWN, ESTIMATED, or UNKNOWN — existing rows default
 * to KNOWN, which is accurate: every prior installation really was
 * recorded live, at the moment it happened.
 *
 * manufacture_date_code is a VMS-observed field (Tire form's "Production
 * Date Code") — free text (tire DOT/production codes are not a clean
 * calendar date), nullable, populated only when the operator has it.
 * No historical value is ever fabricated here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tire_installations', function (Blueprint $table) {
            $table->enum('installation_date_source', ['KNOWN', 'ESTIMATED', 'UNKNOWN'])->default('KNOWN')->after('installed_at');
        });

        Schema::table('tires', function (Blueprint $table) {
            $table->string('manufacture_date_code', 20)->nullable()->after('manufacturer');
        });
    }

    public function down(): void
    {
        Schema::table('tire_installations', function (Blueprint $table) {
            $table->dropColumn('installation_date_source');
        });
        Schema::table('tires', function (Blueprint $table) {
            $table->dropColumn('manufacture_date_code');
        });
    }
};
