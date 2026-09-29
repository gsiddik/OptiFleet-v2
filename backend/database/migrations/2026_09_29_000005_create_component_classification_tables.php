<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Component Classification Master — the mechanical taxonomy beneath
 * Component Group: L2 Category / Assembly and L3 Subcategory / Component
 * Family. Deliberately separate from `product_categories` (the Superadmin
 * commercial/Item-Type catalog that drives the Dynamic Product Form) — the
 * two answer different questions and are never merged.
 *
 * Same ownership model as component_groups: tenant_id NULL = platform
 * baseline visible to every tenant, otherwise tenant-owned. Codes are unique
 * per parent (and per owner), soft-deleted rows included, so a retired code
 * can always be restored and is never silently reused. Parent keys and every
 * key pointing here use ON DELETE NO ACTION: normal deletion is soft delete
 * and history must keep resolving the physical row.
 *
 * Item Type applicability is a normalized pivot keyed by the existing
 * products.product_type values (Item Type is an enum-backed column, not a
 * table, in this codebase).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('component_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('component_group_id');
            $table->string('code', 80);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('is_system')->default(false);
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('component_group_id')->references('id')->on('component_groups')->noActionOnDelete();
            $table->index(['component_group_id', 'status']);
            $table->index(['tenant_id']);
        });
        DB::statement('CREATE UNIQUE INDEX component_categories_platform_code_unique ON component_categories (component_group_id, code) WHERE tenant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX component_categories_tenant_code_unique ON component_categories (tenant_id, component_group_id, code) WHERE tenant_id IS NOT NULL');

        Schema::create('component_subcategories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('component_category_id');
            $table->string('code', 80);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('is_system')->default(false);
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('component_category_id')->references('id')->on('component_categories')->noActionOnDelete();
            $table->index(['component_category_id', 'status']);
            $table->index(['tenant_id']);
        });
        DB::statement('CREATE UNIQUE INDEX component_subcategories_platform_code_unique ON component_subcategories (component_category_id, code) WHERE tenant_id IS NULL');
        DB::statement('CREATE UNIQUE INDEX component_subcategories_tenant_code_unique ON component_subcategories (tenant_id, component_category_id, code) WHERE tenant_id IS NOT NULL');

        Schema::create('component_subcategory_item_types', function (Blueprint $table) {
            $table->uuid('component_subcategory_id');
            $table->string('item_type', 20);
            $table->timestamps();

            $table->primary(['component_subcategory_id', 'item_type'], 'component_subcategory_item_types_primary');
            // Configuration of the master row itself (not history): it goes with the row.
            $table->foreign('component_subcategory_id')->references('id')->on('component_subcategories')->cascadeOnDelete();
            $table->index('item_type');
        });
        DB::statement("ALTER TABLE component_subcategory_item_types ADD CONSTRAINT component_subcategory_item_types_item_type_check CHECK (item_type IN ('SPARE_PART','CONSUMABLE','TIRE','RIM','TOOL','EQUIPMENT'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('component_subcategory_item_types');
        Schema::dropIfExists('component_subcategories');
        Schema::dropIfExists('component_categories');
    }
};
