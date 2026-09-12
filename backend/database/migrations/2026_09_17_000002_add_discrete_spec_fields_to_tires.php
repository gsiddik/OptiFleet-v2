<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * G-11: tire_size was always a single free-text field (e.g. "295/80R22.5"
 * typed as one string). These are additive, nullable, standard ISO
 * tire-sizing components — decomposed data, not a new business policy —
 * and tire_size itself is left untouched for backward compatibility.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tires', function (Blueprint $table) {
            $table->unsignedInteger('section_width_mm')->nullable()->after('tire_size');
            $table->unsignedInteger('aspect_ratio')->nullable()->after('section_width_mm');
            $table->decimal('rim_diameter_inch', 4, 1)->nullable()->after('aspect_ratio');
            $table->unsignedInteger('load_index')->nullable()->after('rim_diameter_inch');
            $table->string('speed_rating', 2)->nullable()->after('load_index');
            $table->unsignedInteger('ply_rating')->nullable()->after('speed_rating');
        });
    }

    public function down(): void
    {
        Schema::table('tires', function (Blueprint $table) {
            $table->dropColumn(['section_width_mm', 'aspect_ratio', 'rim_diameter_inch', 'load_index', 'speed_rating', 'ply_rating']);
        });
    }
};
