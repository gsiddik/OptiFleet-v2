<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F / BD-5: "Sell" is split into three distinct outcomes rather
 * than a single generic disposition — SELL_FOR_OPERATIONAL_REUSE (the
 * tire is fit to keep running), SELL_AS_RETREADABLE_CASING (worn but the
 * casing itself is sound), SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL (neither).
 * tire_scoring_result_id is nullable in the schema (a tire could
 * theoretically be sold as scrap/casing without ever having been scored)
 * but TireService::sell() requires a non-null, non-critical-fail,
 * eligible score specifically for SELL_FOR_OPERATIONAL_REUSE — enforced
 * in code, not the DB, since the other two sell types have no such
 * requirement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tire_sales', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tire_id');
            $table->enum('sell_type', ['SELL_FOR_OPERATIONAL_REUSE', 'SELL_AS_RETREADABLE_CASING', 'SELL_AS_SCRAP_OR_RECYCLABLE_MATERIAL']);
            $table->uuid('tire_scoring_result_id')->nullable();
            $table->text('reason');
            $table->uuid('sold_by')->nullable();
            $table->timestamp('sold_at');
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tire_id')->references('id')->on('tires')->cascadeOnDelete();
            $table->foreign('tire_scoring_result_id')->references('id')->on('tire_scoring_results')->nullOnDelete();
            $table->index(['tenant_id', 'tire_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tire_sales');
    }
};
