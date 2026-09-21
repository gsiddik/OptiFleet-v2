<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Next Improvement Tenant Portal - Products": the Dynamic Product Form's
 * Item Type list (Sparepart, Consumable, Rim, Tire, Tool, Equipment) maps
 * onto the existing `product_type` enum almost exactly — it is missing
 * RIM. This is an additive CHECK-constraint extension (Postgres compiles
 * Laravel's enum() to a plain CHECK, not a native enum type — same
 * pattern as `stock_movements_movement_type_check` before it); every
 * existing SPARE_PART/TOOL/TIRE/CONSUMABLE/EQUIPMENT/OTHER row is
 * unaffected.
 *
 * `product_categories.parent_id` adds Category -> Subcategory as a
 * self-reference, mirroring the exact pattern `component_groups.parent_id`
 * already uses (see 2024_01_02_000005_create_master_data_tables.php) —
 * nullable, defaults to null for every existing row, so flat categories
 * keep behaving exactly as today.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT products_product_type_check');
        DB::statement(
            "ALTER TABLE products ADD CONSTRAINT products_product_type_check ".
            "CHECK (product_type::text = ANY (ARRAY['SPARE_PART','TOOL','TIRE','CONSUMABLE','EQUIPMENT','RIM','OTHER']::character varying[]))"
        );

        Schema::table('product_categories', function (Blueprint $table) {
            $table->uuid('parent_id')->nullable()->after('tenant_id');
            $table->index(['tenant_id', 'parent_id']);
        });
        Schema::table('product_categories', function (Blueprint $table) {
            $table->foreign('parent_id')->references('id')->on('product_categories')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
        });

        DB::statement('ALTER TABLE products DROP CONSTRAINT products_product_type_check');
        DB::statement(
            "ALTER TABLE products ADD CONSTRAINT products_product_type_check ".
            "CHECK (product_type::text = ANY (ARRAY['SPARE_PART','TOOL','TIRE','CONSUMABLE','EQUIPMENT','OTHER']::character varying[]))"
        );
    }
};
