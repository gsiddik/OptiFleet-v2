<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Improvement OptiFleet - Maintenance Request dan Work Order": the doc's
 * Work Order Parts flow is genuinely two different tabs, not one renamed:
 *
 * - "Planned Parts" (doc, new meaning): a pure budgeting line item — pick
 *   a catalog Product (Sparepart/Tire/Consumable only), a Qty, nothing
 *   else. No Reserve/Issue/Consume/Return ("jangan tampilkan tombol
 *   Reserve, tombol Issue, Consume atau Return"). Feeds Estimated Parts
 *   Cost (Qty x the Product's price) and nothing else — it never touches
 *   warehouse stock at all.
 * - "Request Parts" (doc: "sebelumnya adalah Tab Planned Parts yang
 *   berubah nama" — literally the OLD Planned Parts tab, renamed): the
 *   existing `work_order_planned_parts` / WorkOrderPlannedPart table,
 *   completely unchanged — it already has the full Reserve -> Issue ->
 *   Consume -> Return lifecycle the doc describes for this tab. Renaming
 *   the table/API path itself would be a large, purely-cosmetic, high-
 *   risk change for something that already works and is heavily tested;
 *   only the frontend tab LABEL and its visibility window change (see
 *   the frontend Batch 11 detail in the status doc).
 *
 * This table backs the genuinely new "Planned Parts" concept — deliberately
 * thin (no status/warehouse/cost-snapshot columns at all) since it never
 * participates in the Reserve/Issue/Consume/Return lifecycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_order_planned_part_estimates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('work_order_id');
            $table->uuid('product_id');
            $table->decimal('quantity', 16, 4);
            $table->text('notes')->nullable();
            $table->uuid('created_by')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('work_order_id')->references('id')->on('work_orders')->cascadeOnDelete();
            $table->foreign('product_id')->references('id')->on('products')->restrictOnDelete();
            $table->index(['tenant_id', 'work_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_order_planned_part_estimates');
    }
};
