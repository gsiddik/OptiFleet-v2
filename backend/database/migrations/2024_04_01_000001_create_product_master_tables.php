<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_system')->default(false);
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->index(['tenant_id', 'status']);
        });
        DB::statement('CREATE UNIQUE INDEX product_categories_system_code_unique ON product_categories (code) WHERE tenant_id IS NULL AND deleted_at IS NULL');

        Schema::create('uoms', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('code');
            $table->string('name');
            $table->boolean('is_system')->default(false);
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->unique(['tenant_id', 'code']);
        });
        DB::statement('CREATE UNIQUE INDEX uoms_system_code_unique ON uoms (code) WHERE tenant_id IS NULL AND deleted_at IS NULL');

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->string('code');
            $table->string('sku');
            $table->string('name');
            $table->uuid('product_category_id');
            $table->enum('product_type', ['SPARE_PART', 'TOOL', 'TIRE', 'CONSUMABLE', 'EQUIPMENT', 'OTHER'])->default('SPARE_PART');
            $table->uuid('uom_id');
            $table->string('brand')->nullable();
            $table->string('manufacturer_part_number')->nullable();
            $table->text('description')->nullable();
            $table->boolean('track_serial_number')->default(false);
            $table->boolean('track_batch')->default(false);
            $table->boolean('is_system')->default(false);
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('product_category_id')->references('id')->on('product_categories')->restrictOnDelete();
            $table->foreign('uom_id')->references('id')->on('uoms')->restrictOnDelete();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'sku']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'product_type']);
        });
        DB::statement('CREATE UNIQUE INDEX products_system_code_unique ON products (code) WHERE tenant_id IS NULL AND deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX products_system_sku_unique ON products (sku) WHERE tenant_id IS NULL AND deleted_at IS NULL');

        Schema::create('product_component_groups', function (Blueprint $table) {
            $table->uuid('product_id');
            $table->uuid('component_group_id');
            $table->timestamps();

            $table->primary(['product_id', 'component_group_id'], 'product_cg_primary');
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('component_group_id')->references('id')->on('component_groups')->cascadeOnDelete();
        });

        // One row = one compatibility rule for a product. Every dimension is
        // nullable/optional; a query matches a vehicle+component-group pair
        // against any row whose non-null dimensions all match, then orders
        // by specificity (most non-null dimensions matched wins) so a
        // narrower rule (e.g. brand+model) outranks a broader one (e.g.
        // category-only) for the same product line.
        Schema::create('product_compatibilities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id')->nullable();
            $table->uuid('product_id');
            $table->uuid('component_group_id')->nullable();
            $table->uuid('vehicle_category_id')->nullable();
            $table->string('vehicle_brand')->nullable();
            $table->string('vehicle_model')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->cascadeOnDelete();
            $table->foreign('component_group_id')->references('id')->on('component_groups')->cascadeOnDelete();
            $table->foreign('vehicle_category_id')->references('id')->on('vehicle_categories')->cascadeOnDelete();
            $table->index(['product_id']);
            $table->index(['vehicle_category_id', 'vehicle_brand', 'vehicle_model']);
            $table->index(['component_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_compatibilities');
        Schema::dropIfExists('product_component_groups');
        Schema::dropIfExists('products');
        Schema::dropIfExists('uoms');
        Schema::dropIfExists('product_categories');
    }
};
