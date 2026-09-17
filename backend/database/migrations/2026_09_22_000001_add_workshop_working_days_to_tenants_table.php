<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 6: Workshop Working Days drives the Planning & Schedule working-day
 * calculation (5/6/7). Left nullable with no invented default — existing
 * tenants must complete Company Profile before the value is used, rather
 * than silently assuming a value that could shift their schedules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->unsignedTinyInteger('workshop_working_days')->nullable()->after('timezone');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('workshop_working_days');
        });
    }
};
