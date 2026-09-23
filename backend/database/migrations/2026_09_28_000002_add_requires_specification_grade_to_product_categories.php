<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Owner decision: whether a Consumable's Specification/Grade field is
 * Conditional-Mandatory is driven by Category/Subcategory — not a new
 * Consumable "type", and not a hardcoded frontend string comparison
 * against a category's name/code. Product Categories are Superadmin-
 * managed platform master data (`tenant_id` always null here — see
 * ProductCategoryController's docblock), so a boolean flag on the
 * category row itself is stable, centrally controlled metadata, not
 * tenant-mutable data a hardcoded rule could silently break against.
 * Category and Subcategory are both rows in this same self-referencing
 * table, and a Product only ever stores ONE `product_category_id` — the
 * leaf the user picked (Subcategory if chosen, else Category) — so a
 * single flag on whichever row that is covers both granularities without
 * any "climb to parent" logic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->boolean('requires_specification_grade')->default(false)->after('item_type');
        });
    }

    public function down(): void
    {
        Schema::table('product_categories', function (Blueprint $table) {
            $table->dropColumn('requires_specification_grade');
        });
    }
};
