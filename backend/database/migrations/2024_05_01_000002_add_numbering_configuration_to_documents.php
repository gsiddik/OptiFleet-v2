<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 6: every configurable-numbering document preserves which
 * numbering configuration version was in effect when it was issued.
 * Nullable and additive only — existing rows simply have no value here,
 * their document_number columns are completely untouched.
 */
return new class extends Migration
{
    private const TABLES = [
        'work_orders', 'maintenance_requests', 'vehicle_transfers', 'stock_transfers',
        'purchase_requests', 'rfqs', 'purchase_orders', 'goods_receipts', 'warranty_claims',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->uuid('numbering_configuration_version_id')->nullable()->after('id');
                $blueprint->foreign('numbering_configuration_version_id', "{$table}_numbering_config_fk")
                    ->references('id')->on('configuration_versions')->nullOnDelete();
            });
        }

        Schema::table('vehicle_transfers', function (Blueprint $blueprint) {
            $blueprint->string('transfer_number')->nullable()->after('numbering_configuration_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_transfers', function (Blueprint $blueprint) {
            $blueprint->dropColumn('transfer_number');
        });
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign("{$table}_numbering_config_fk");
                $blueprint->dropColumn('numbering_configuration_version_id');
            });
        }
    }
};
