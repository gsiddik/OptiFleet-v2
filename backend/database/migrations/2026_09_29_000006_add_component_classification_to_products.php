<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Product -> Component Group / Category / Subcategory (the mechanical
 * classification). Nullable: no existing Product is backfilled or guessed
 * from its name, and Tools/Equipment legitimately have no mechanical
 * classification. Consistency (Subcategory under Category under Group, Item
 * Type applicability, effective availability) is enforced by
 * ComponentClassificationService. ON DELETE NO ACTION everywhere: a master
 * row is only ever soft-deleted, and a Product never loses its reference.
 *
 * Independent of the existing product_component_groups pivot (compatibility
 * / multi-group tagging) and of product_category_id (commercial catalog).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->uuid('component_group_id')->nullable()->after('product_category_id');
            $table->uuid('component_category_id')->nullable()->after('component_group_id');
            $table->uuid('component_subcategory_id')->nullable()->after('component_category_id');

            $table->foreign('component_group_id')->references('id')->on('component_groups')->noActionOnDelete();
            $table->foreign('component_category_id')->references('id')->on('component_categories')->noActionOnDelete();
            $table->foreign('component_subcategory_id')->references('id')->on('component_subcategories')->noActionOnDelete();
            $table->index(['tenant_id', 'component_group_id']);
            $table->index('component_category_id');
            $table->index('component_subcategory_id');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['component_group_id']);
            $table->dropForeign(['component_category_id']);
            $table->dropForeign(['component_subcategory_id']);
            $table->dropIndex(['tenant_id', 'component_group_id']);
            $table->dropIndex(['component_category_id']);
            $table->dropIndex(['component_subcategory_id']);
            $table->dropColumn(['component_group_id', 'component_category_id', 'component_subcategory_id']);
        });
    }
};
