<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Next Improvement Tenant Portal - Products" (authoritative document,
 * General Information table): Category is "berdasarkan Item Type" — the
 * Category dropdown must be filterable by the selected Item Type. Additive,
 * nullable classification on top-level categories (parent_id IS NULL);
 * a subcategory inherits its parent's item_type at the application layer
 * rather than duplicating it, so it can never drift from its parent.
 *
 * Vehicle Compatibility (Sparepart/Rim spec, "saya sarankan form": Make/
 * Model/Variant/Year From/Year To/Position) is mostly covered by the
 * existing product_compatibilities table (component_group_id/
 * vehicle_category_id/vehicle_brand/vehicle_model), which this cycle's
 * decisions require reusing unchanged rather than replacing — only the
 * missing dimensions are added additively.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->enum('item_type', ['SPARE_PART', 'TOOL', 'TIRE', 'CONSUMABLE', 'EQUIPMENT', 'RIM', 'OTHER'])->nullable()->after('parent_id');
        });

        Schema::table('product_compatibilities', function (Blueprint $table) {
            $table->string('variant')->nullable()->after('vehicle_model');
            $table->unsignedSmallInteger('year_from')->nullable()->after('variant');
            $table->unsignedSmallInteger('year_to')->nullable()->after('year_from');
            $table->string('position')->nullable()->after('year_to');
        });
    }

    public function down(): void
    {
        Schema::table('product_compatibilities', function (Blueprint $table) {
            $table->dropColumn(['variant', 'year_from', 'year_to', 'position']);
        });

        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropColumn('item_type');
        });
    }
};
