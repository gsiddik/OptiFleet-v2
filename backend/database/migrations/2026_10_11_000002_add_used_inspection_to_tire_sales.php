<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tire Scoring is retired in favour of the Used Tire Inspection engine (additive only): a sale
 * for operational reuse now records the approved inspection that justified it. The legacy
 * tire_scoring_result_id column and the tire_scoring_results table are kept as historical data.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tire_sales', function (Blueprint $table) {
            $table->uuid('tire_used_inspection_id')->nullable()->after('tire_scoring_result_id');
            $table->foreign('tire_used_inspection_id')->references('id')->on('tire_used_inspections')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tire_sales', function (Blueprint $table) {
            $table->dropForeign(['tire_used_inspection_id']);
            $table->dropColumn('tire_used_inspection_id');
        });
    }
};
