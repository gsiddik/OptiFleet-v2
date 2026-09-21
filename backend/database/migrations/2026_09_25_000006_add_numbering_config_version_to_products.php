<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Next Improvement Tenant Portal - Products" Section 20: Item Code is now
 * server-generated through the existing DocumentNumberingService (extended
 * with a new 'product_item' document type) rather than client-typed. This
 * column records which numbering configuration version produced a given
 * product's code, the same audit pattern goods_receipts.numbering_configuration_version_id
 * already uses. Nullable so pre-existing (client-typed) products are left
 * untouched — their code's provenance is simply unknown, never reinterpreted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->uuid('numbering_configuration_version_id')->nullable()->after('code');
            $table->foreign('numbering_configuration_version_id')->references('id')->on('configuration_versions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropForeign(['numbering_configuration_version_id']);
            $table->dropColumn('numbering_configuration_version_id');
        });
    }
};
