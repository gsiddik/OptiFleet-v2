<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final reconciliation (queued ADJUST): VMS lists Construction (Radial/
 * Bias) and Tire Type (Tubeless/Tube) as discrete Tire fields; every other
 * VMS tire field was already covered in the real Phase G G-11 batch except
 * these two. Both are fixed, small enums validated at the application
 * layer (FormRequest) — no DB CHECK constraint, matching the convention
 * already used for `speed_rating`-style tire spec fields, since neither
 * gates a downstream business rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tires', function (Blueprint $table) {
            $table->string('construction_type')->nullable()->after('pattern');
            $table->string('tube_type')->nullable()->after('construction_type');
        });
    }

    public function down(): void
    {
        Schema::table('tires', function (Blueprint $table) {
            $table->dropColumn(['construction_type', 'tube_type']);
        });
    }
};
