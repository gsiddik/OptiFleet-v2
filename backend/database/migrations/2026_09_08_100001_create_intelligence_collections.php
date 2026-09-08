<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Phase 7 — Maintenance Intelligence MongoDB collections. Guarded with
 * hasCollection()/hasIndex() so this migration is safe to run again
 * (migrate:fresh only resets PostgreSQL, matching the Phase 6 migration
 * convention).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('mongodb');

        // --- Feature store (Section 4): one document per (tenant, feature_date, entity) ---
        $this->ensure($schema, 'vehicle_daily_features', function (Blueprint $b) {
            $b->unique(['tenant_id', 'feature_date', 'vehicle_id'], 'uniq_key');
            $b->index(['tenant_id', 'vehicle_id', 'feature_date'], 'idx_trend');
        });
        $this->ensure($schema, 'component_daily_features', function (Blueprint $b) {
            $b->unique(['tenant_id', 'feature_date', 'vehicle_id', 'component_group_id'], 'uniq_key');
            $b->index(['tenant_id', 'component_group_id', 'feature_date'], 'idx_trend');
        });
        $this->ensure($schema, 'tire_daily_features', function (Blueprint $b) {
            $b->unique(['tenant_id', 'feature_date', 'tire_id'], 'uniq_key');
            $b->index(['tenant_id', 'tire_id', 'feature_date'], 'idx_trend');
        });

        // --- Model registry (Section 9): versions never overwritten, so the
        // unique key includes version; "one ACTIVE" is a service-level
        // invariant (ModelRegistryService), not a unique index, since many
        // RETIRED/EVALUATED versions legitimately coexist. ---
        $this->ensure($schema, 'intelligence_models', function (Blueprint $b) {
            $b->unique(['model_code', 'scope', 'tenant_id', 'version'], 'uniq_version');
            $b->index(['model_code', 'scope', 'tenant_id', 'status'], 'idx_active_lookup');
        });

        // --- Predictions (Section 36): re-running the same day's inference
        // for the same entity/target/horizon upserts, distinct
        // source_data_as_of values accumulate history. ---
        $this->ensure($schema, 'intelligence_predictions', function (Blueprint $b) {
            $b->unique(['tenant_id', 'entity_type', 'entity_id', 'prediction_type', 'horizon_days', 'source_data_as_of'], 'uniq_key');
            $b->index(['tenant_id', 'entity_type', 'entity_id', 'predicted_at'], 'idx_history');
            $b->index(['tenant_id', 'risk_level', 'predicted_at'], 'idx_risk');
        });

        // --- Recommendations (Section 37) ---
        $this->ensure($schema, 'intelligence_recommendations', function (Blueprint $b) {
            $b->index(['tenant_id', 'status', 'created_at'], 'idx_status');
            $b->index(['tenant_id', 'entity_type', 'entity_id'], 'idx_entity');
            $b->index(['prediction_id'], 'idx_prediction');
        });

        // --- Outcome feedback (Section 40-41): append-only ---
        $this->ensure($schema, 'intelligence_outcomes', function (Blueprint $b) {
            $b->index(['tenant_id', 'prediction_id'], 'idx_prediction');
            $b->index(['tenant_id', 'entity_type', 'entity_id', 'occurred_at'], 'idx_entity');
        });
    }

    private function ensure($schema, string $collection, callable $indexes): void
    {
        if (! $schema->hasCollection($collection)) {
            $schema->create($collection);
        }
        $schema->table($collection, $indexes);
    }

    public function down(): void
    {
        $schema = Schema::connection('mongodb');
        foreach ([
            'vehicle_daily_features', 'component_daily_features', 'tire_daily_features',
            'intelligence_models', 'intelligence_predictions', 'intelligence_recommendations',
            'intelligence_outcomes',
        ] as $collection) {
            $schema->dropIfExists($collection);
        }
    }
};
