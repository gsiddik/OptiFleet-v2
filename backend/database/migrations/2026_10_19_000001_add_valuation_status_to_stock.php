<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Valuation STATUS next to the existing moving-average cost (schema only — no value is changed here, see the backfill
 * migration for the deterministic initial classification). Purchase price, valuation basis and valuation status are
 * three separate facts:
 *  - stock_movements (inbound, value-bearing): purchase_unit_price, valuation_basis, valuation_status;
 *  - warehouse_stocks (the balance): valuation_status (NULL = never classified / no stock), valuation_basis;
 *  - stock_transfer_items: the source balance's status at dispatch, so a transfer receipt does not launder it;
 *  - stock_valuation_reviews: append-only log of every manual status change (actor, time, basis, reason, evidence).
 */
return new class extends Migration
{
    private const STATUSES = "('VERIFIED','VERIFIED_ZERO','NOT_VALUED','UNVERIFIED','MIXED')";

    public function up(): void
    {
        Schema::table('stock_movements', function (Blueprint $table) {
            $table->decimal('purchase_unit_price', 16, 4)->nullable();
            $table->string('valuation_basis', 40)->nullable();
            $table->string('valuation_status', 20)->nullable();
        });
        Schema::table('warehouse_stocks', function (Blueprint $table) {
            $table->string('valuation_status', 20)->nullable();
            $table->string('valuation_basis', 40)->nullable();
            $table->index(['tenant_id', 'valuation_status']);
        });
        Schema::table('stock_transfer_items', function (Blueprint $table) {
            $table->string('valuation_status', 20)->nullable();
        });
        DB::statement('ALTER TABLE stock_movements ADD CONSTRAINT stock_movements_valuation_status_check CHECK (valuation_status IS NULL OR valuation_status IN '.self::STATUSES.')');
        DB::statement('ALTER TABLE warehouse_stocks ADD CONSTRAINT warehouse_stocks_valuation_status_check CHECK (valuation_status IS NULL OR valuation_status IN '.self::STATUSES.')');
        DB::statement('ALTER TABLE stock_transfer_items ADD CONSTRAINT stock_transfer_items_valuation_status_check CHECK (valuation_status IS NULL OR valuation_status IN '.self::STATUSES.')');

        Schema::create('stock_valuation_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('warehouse_stock_id');
            $table->uuid('warehouse_id');
            $table->uuid('product_id');
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('basis', 40);
            $table->text('reason');
            $table->string('evidence_reference', 255)->nullable();
            $table->decimal('quantity_at_review', 16, 4);
            $table->decimal('unit_cost_at_review', 16, 4);
            $table->boolean('acknowledged_mixed_sources')->default(false);
            $table->uuid('reviewed_by')->nullable();
            $table->timestamp('reviewed_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('warehouse_stock_id')->references('id')->on('warehouse_stocks')->cascadeOnDelete();
            $table->index(['tenant_id', 'warehouse_stock_id', 'reviewed_at']);
        });
        DB::statement('ALTER TABLE stock_valuation_reviews ADD CONSTRAINT stock_valuation_reviews_to_status_check CHECK (to_status IN '.self::STATUSES.')');
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_valuation_reviews');
        DB::statement('ALTER TABLE stock_transfer_items DROP CONSTRAINT IF EXISTS stock_transfer_items_valuation_status_check');
        DB::statement('ALTER TABLE warehouse_stocks DROP CONSTRAINT IF EXISTS warehouse_stocks_valuation_status_check');
        DB::statement('ALTER TABLE stock_movements DROP CONSTRAINT IF EXISTS stock_movements_valuation_status_check');
        Schema::table('stock_transfer_items', fn (Blueprint $t) => $t->dropColumn('valuation_status'));
        Schema::table('warehouse_stocks', function (Blueprint $t) {
            $t->dropIndex(['tenant_id', 'valuation_status']);
            $t->dropColumn(['valuation_status', 'valuation_basis']);
        });
        Schema::table('stock_movements', fn (Blueprint $t) => $t->dropColumn(['purchase_unit_price', 'valuation_basis', 'valuation_status']));
    }
};
