<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Next Improvement Tenant Portal - Products" (Units of Measure): adds
 * Type of Measure (Length/Packaging/Capacity/Weight/Pressure) as a
 * nullable classification column on the existing flat `uoms` table —
 * additive, every existing UOM row simply has `measure_type = NULL`
 * (unclassified) until edited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('uoms', function (Blueprint $table) {
            $table->enum('measure_type', ['LENGTH', 'PACKAGING', 'CAPACITY', 'WEIGHT', 'PRESSURE'])->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('uoms', function (Blueprint $table) {
            $table->dropColumn('measure_type');
        });
    }
};
