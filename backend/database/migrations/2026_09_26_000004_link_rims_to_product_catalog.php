<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Resolves the Phase 0 blocker: the standalone `rims` table (a working,
 * tenant-owned catalog+asset hybrid with its own CRUD, predating this
 * cycle) now sits alongside the new `product_rims` Item Master spec table
 * introduced for the Dynamic Product Form's RIM Item Type. Rather than
 * merging or rewriting the existing Rim screen (a destructive change with
 * no source requirement asking for it), this adds the same bridging
 * pattern Tire already uses (`tires.product_id`) — nullable, since every
 * existing rim predates any catalog concept and must keep working
 * unmodified. Wiring actual Goods-Receipt-time rim asset creation from a
 * catalog Product(RIM) is deferred; this column only makes the future
 * reconciliation possible without a breaking migration later.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rims', function (Blueprint $table) {
            $table->uuid('product_id')->nullable()->after('tenant_id');
            $table->foreign('product_id')->references('id')->on('products')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rims', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn('product_id');
        });
    }
};
