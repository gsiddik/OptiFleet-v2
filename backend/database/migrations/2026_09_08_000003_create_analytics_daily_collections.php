<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Phase 6 Sections 4, 9, 53: the daily analytical projection collections.
 * Every collection shares the same shape: one document per
 * (tenant_id, snapshot_date, <dimension>) — the dimension is nullable and
 * represents the tenant-wide rollup document when null. That triple is
 * the idempotency key (Section 9): re-running a business date upserts
 * the same document rather than creating a duplicate, which is also what
 * makes late-arriving corrections (Section 11) safe to reprocess.
 *
 * A secondary (tenant_id, dimension, snapshot_date) index supports the
 * trend/range queries the dashboard actually runs (Section 43) — "this
 * vehicle/branch/workshop over the last N days" — without a collection
 * scan. Guarded with hasCollection() because `migrate:fresh` only resets
 * PostgreSQL; this migration must be safe to run again.
 */
return new class extends Migration
{
    /** collection => dimension field name (null-able secondary key) */
    private const COLLECTIONS = [
        'daily_fleet_snapshots' => 'branch_id',
        'daily_vehicle_health' => 'vehicle_id',
        'daily_maintenance_metrics' => 'branch_id',
        'daily_work_order_metrics' => 'workshop_id',
        'daily_breakdown_metrics' => 'branch_id',
        'daily_downtime_metrics' => 'vehicle_id',
        'daily_workshop_metrics' => 'workshop_id',
        'daily_mechanic_metrics' => 'mechanic_id',
        'daily_inventory_metrics' => 'warehouse_id',
        'daily_procurement_metrics' => 'branch_id',
        'daily_vendor_metrics' => 'vendor_id',
        'daily_cost_metrics' => 'branch_id',
        'daily_tire_metrics' => 'branch_id',
        'daily_component_failure_metrics' => 'component_group_id',
        'daily_warranty_metrics' => 'branch_id',
    ];

    public function up(): void
    {
        $schema = Schema::connection('mongodb');

        foreach (self::COLLECTIONS as $collection => $dimension) {
            if (! $schema->hasCollection($collection)) {
                $schema->create($collection);
            }

            $schema->table($collection, function (Blueprint $blueprint) use ($dimension) {
                $blueprint->unique(['tenant_id', 'snapshot_date', $dimension], 'uniq_key');
                $blueprint->index(['tenant_id', $dimension, 'snapshot_date'], 'idx_trend');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('mongodb');
        foreach (array_keys(self::COLLECTIONS) as $collection) {
            $schema->dropIfExists($collection);
        }
    }
};
