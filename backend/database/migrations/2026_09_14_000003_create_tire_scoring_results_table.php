<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase F / G-31 / BD-1 / BD-3: structured tire scoring. Each row is one
 * calculation, snapshotting the exact source measurements (KTS/KTN) and
 * the exact configuration_version used, so a later change to the tire's
 * reference tread depth or to the scoring configuration can never alter
 * a past result — immutability is enforced in TireScoringService (no
 * update is permitted once finalized_at is set), not by a DB trigger,
 * matching this codebase's established pattern for other finalized
 * records.
 *
 * critical_safety_fail is a plain boolean, independent of `classification`
 * (which comes from the versioned, tenant-editable band configuration) —
 * BD-1/BD-6 require the critical-fail gate to override every other score
 * unconditionally, so callers must check this column directly rather
 * than infer safety from the classification label.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tire_scoring_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('tenant_id');
            $table->uuid('tire_id');
            $table->uuid('tire_inspection_id');
            $table->uuid('tire_retread_id')->nullable();
            $table->uuid('tire_repair_id')->nullable();
            $table->enum('scoring_type', ['REPAIR', 'RETREAD']);
            $table->uuid('configuration_version_id');

            // KTN: reference (new/original) tread depth, snapshotted from the Tire's Product at calc time.
            $table->decimal('reference_tread_depth_mm', 6, 2);
            // KTS: actual measured remaining tread depth, snapshotted from the source TireInspection.
            $table->decimal('measured_tread_depth_mm', 6, 2);
            // SPA: system-calculated percentage (measured / reference * 100) and its configured-band normalization.
            $table->decimal('spa_raw_percent', 6, 2);
            $table->decimal('spa_normalized_score', 6, 2);
            $table->string('classification', 50);
            // KA: inspector-supplied supplementary condition score — captured, never computed by an invented formula.
            $table->decimal('ka_score', 6, 2)->nullable();
            // KF: supporting composite score only when the config defines weights — never a gating value.
            $table->decimal('kf_score', 6, 2)->nullable();
            $table->boolean('critical_safety_fail')->default(false);
            $table->text('critical_safety_reasons')->nullable();
            $table->boolean('eligible_for_operational_reuse');

            $table->uuid('computed_by')->nullable();
            $table->timestamp('computed_at');
            $table->uuid('finalized_by')->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
            $table->foreign('tire_id')->references('id')->on('tires')->cascadeOnDelete();
            $table->foreign('tire_inspection_id')->references('id')->on('tire_inspections')->restrictOnDelete();
            $table->foreign('tire_retread_id')->references('id')->on('tire_retreads')->nullOnDelete();
            $table->foreign('tire_repair_id')->references('id')->on('tire_repairs')->nullOnDelete();
            $table->foreign('configuration_version_id')->references('id')->on('configuration_versions')->restrictOnDelete();
            $table->index(['tenant_id', 'tire_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tire_scoring_results');
    }
};
