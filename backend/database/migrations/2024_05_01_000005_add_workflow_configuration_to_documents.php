<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section 25: every migrated resource preserves which workflow
 * configuration version was in effect when it was created — a transition
 * attempt on that resource is always validated against exactly that
 * version, never whatever is currently published, so publishing a new
 * workflow version never silently changes the rules for a resource already
 * in flight. Nullable and additive only — existing rows just have no value
 * here.
 */
return new class extends Migration
{
    // Every table here except 'breakdowns' already has numbering_configuration_version_id (Batch B) —
    // breakdowns are never a numbered document, so it gets no "after" anchor.
    private const TABLES = [
        'maintenance_requests', 'work_orders', 'vehicle_transfers', 'breakdowns',
        'stock_transfers', 'purchase_requests', 'purchase_orders', 'warranty_claims',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if ($table === 'breakdowns') {
                    $blueprint->uuid('workflow_configuration_version_id')->nullable()->after('id');
                } else {
                    $blueprint->uuid('workflow_configuration_version_id')->nullable()->after('numbering_configuration_version_id');
                }
                $blueprint->foreign('workflow_configuration_version_id', "{$table}_workflow_config_fk")
                    ->references('id')->on('configuration_versions')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                $blueprint->dropForeign("{$table}_workflow_config_fk");
                $blueprint->dropColumn('workflow_configuration_version_id');
            });
        }
    }
};
