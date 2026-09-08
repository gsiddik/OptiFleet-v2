<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 6 Section 56: business-day aggregation (ETL business_date, KPI
 * period boundaries) must honor the tenant's own timezone rather than the
 * storage timezone (UTC, per existing APP_TIMEZONE convention). Additive
 * only — nullable, defaults to UTC — so Phase 1-5 behavior is unchanged
 * for every tenant until explicitly configured otherwise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('timezone')->default('UTC')->after('industry');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('timezone');
        });
    }
};
