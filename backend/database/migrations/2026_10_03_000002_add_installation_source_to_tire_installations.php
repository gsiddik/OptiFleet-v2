<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Where a tire installation came from. Existing rows (and every live install) are STANDARD;
 * INITIAL_REGISTRATION marks a tire recorded from Vehicle Detail → Wheels Configuration as the
 * vehicle's baseline (already on the vehicle before/outside OptiFleet — no warehouse issue).
 * installation_date_source (KNOWN/ESTIMATED/UNKNOWN) stays about the date's reliability only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tire_installations', function (Blueprint $table) {
            $table->string('installation_source', 30)->default('STANDARD')->after('installation_date_source');
        });
        DB::statement("ALTER TABLE tire_installations ADD CONSTRAINT tire_installations_source_check CHECK (installation_source IN ('STANDARD', 'INITIAL_REGISTRATION'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE tire_installations DROP CONSTRAINT IF EXISTS tire_installations_source_check');
        Schema::table('tire_installations', function (Blueprint $table) {
            $table->dropColumn('installation_source');
        });
    }
};
