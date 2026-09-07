<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 reuses the Phase 1 entitlement tables rather than introducing a
 * second entitlement system — this migration only adds nullable provenance
 * columns so a provisioned entitlement can be traced back to the contract
 * (and specific item) that granted it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_module_entitlements', function (Blueprint $table) {
            $table->uuid('contract_id')->nullable()->after('source');
            $table->uuid('contract_item_id')->nullable()->after('contract_id');

            $table->foreign('contract_id')->references('id')->on('contracts')->nullOnDelete();
            $table->foreign('contract_item_id')->references('id')->on('contract_items')->nullOnDelete();
        });

        Schema::table('tenant_capacity_limits', function (Blueprint $table) {
            $table->uuid('contract_id')->nullable();
            $table->uuid('contract_item_id')->nullable();

            $table->foreign('contract_id')->references('id')->on('contracts')->nullOnDelete();
            $table->foreign('contract_item_id')->references('id')->on('contract_items')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('tenant_module_entitlements', function (Blueprint $table) {
            $table->dropForeign(['contract_id']);
            $table->dropForeign(['contract_item_id']);
            $table->dropColumn(['contract_id', 'contract_item_id']);
        });

        Schema::table('tenant_capacity_limits', function (Blueprint $table) {
            $table->dropForeign(['contract_id']);
            $table->dropForeign(['contract_item_id']);
            $table->dropColumn(['contract_id', 'contract_item_id']);
        });
    }
};
