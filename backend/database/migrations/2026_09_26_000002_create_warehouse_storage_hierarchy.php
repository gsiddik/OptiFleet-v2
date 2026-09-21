<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Next Improvement Tenant Portal - Products" General Information: "Default
 * Storage Location | M | Hierarchical Lookup | Warehouse -> Zone -> Rack ->
 * Bin". No such hierarchy exists anywhere in the codebase today — this is
 * net-new tenant-owned physical structure under the existing Warehouse
 * model, following the same tenant/timestamps/softDeletes shape Warehouse
 * itself uses (BelongsToTenant, not tenant-or-platform, since a Zone/Rack/
 * Bin is always a specific tenant's physical location, never platform
 * seed data).
 *
 * `products.default_storage_bin_id` is nullable at the schema level (every
 * existing product keeps working with no location) even though the
 * Dynamic Product Form treats it as Mandatory going forward — enforced at
 * the request-validation layer for new creates, per this cycle's backward
 * compatibility rule.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouse_zones', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('warehouse_id');
            $table->string('code');
            $table->string('name');
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
            $table->unique(['warehouse_id', 'code']);
            $table->index(['tenant_id', 'warehouse_id']);
        });

        Schema::create('warehouse_racks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('warehouse_zone_id');
            $table->string('code');
            $table->string('name');
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('warehouse_zone_id')->references('id')->on('warehouse_zones')->cascadeOnDelete();
            $table->unique(['warehouse_zone_id', 'code']);
            $table->index(['tenant_id', 'warehouse_zone_id']);
        });

        Schema::create('warehouse_bins', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('warehouse_rack_id');
            $table->string('code');
            $table->string('name');
            $table->enum('status', ['ACTIVE', 'INACTIVE'])->default('ACTIVE');
            $table->timestamps();
            $table->softDeletes();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('warehouse_rack_id')->references('id')->on('warehouse_racks')->cascadeOnDelete();
            $table->unique(['warehouse_rack_id', 'code']);
            $table->index(['tenant_id', 'warehouse_rack_id']);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->uuid('default_storage_bin_id')->nullable()->after('uom_id');
            $table->foreign('default_storage_bin_id')->references('id')->on('warehouse_bins')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['default_storage_bin_id']);
            $table->dropColumn('default_storage_bin_id');
        });

        Schema::dropIfExists('warehouse_bins');
        Schema::dropIfExists('warehouse_racks');
        Schema::dropIfExists('warehouse_zones');
    }
};
