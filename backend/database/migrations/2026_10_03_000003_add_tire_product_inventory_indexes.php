<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tire List is product-level with New / Installed / Used counts aggregated from physical tires
 * (one grouped query). These indexes back that aggregation and the per-product inventory tables,
 * and the Usage KM lookup of a removal by its installation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tires', function (Blueprint $table) {
            $table->index(['tenant_id', 'product_id'], 'tires_tenant_product_idx');
        });
        Schema::table('tire_removals', function (Blueprint $table) {
            $table->index('tire_installation_id', 'tire_removals_installation_idx');
        });
    }

    public function down(): void
    {
        Schema::table('tires', fn (Blueprint $table) => $table->dropIndex('tires_tenant_product_idx'));
        Schema::table('tire_removals', fn (Blueprint $table) => $table->dropIndex('tire_removals_installation_idx'));
    }
};
