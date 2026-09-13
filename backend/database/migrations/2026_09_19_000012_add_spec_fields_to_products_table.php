<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Final reconciliation: the VMS Product form lists Manufacturer, Material,
 * Production Year, Dimension/Size/Weight, and Image alongside the fields
 * Product already has (Type/Category/Part Number/Unit/description). All
 * are plain descriptive/physical attributes. `image_url` follows the same
 * shallow nullable-string-URL pattern as `evidence`/`photo_url` elsewhere.
 * Deliberately NOT added: a condition-quality "Status" field (Original/
 * Aftermarket/KW/Rusak) — this is a genuinely new taxonomy distinct from
 * the existing lifecycle `status` (ACTIVE/INACTIVE) and conflating the two
 * would be confusing; left as DEFERRED_DECISION (see
 * IMPROVEMENT_CONTEXT.md). SKU auto-generation also remains deferred —
 * no source material defines an actual numbering format.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('manufacturer')->nullable()->after('brand');
            $table->string('material')->nullable()->after('manufacturer');
            $table->unsignedSmallInteger('production_year')->nullable()->after('material');
            $table->decimal('weight_kg', 10, 3)->nullable()->after('production_year');
            $table->decimal('length_mm', 10, 1)->nullable()->after('weight_kg');
            $table->decimal('width_mm', 10, 1)->nullable()->after('length_mm');
            $table->decimal('height_mm', 10, 1)->nullable()->after('width_mm');
            $table->string('image_url')->nullable()->after('height_mm');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['manufacturer', 'material', 'production_year', 'weight_kg', 'length_mm', 'width_mm', 'height_mm', 'image_url']);
        });
    }
};
