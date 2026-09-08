<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Intelligence\Labels\LabelBuilderRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 Section 40-41: outcome tracking. Reuses the exact same
 * LabelBuilder a target's training pipeline uses (Section 41's own "no
 * label leakage" guarantee applies here too) to check, once a
 * prediction's horizon has actually elapsed, whether the predicted
 * event occurred — this is the ground truth future model
 * evaluation/retraining (Section 48) needs.
 */
class OutcomeFeedbackService
{
    public function __construct(private readonly LabelBuilderRegistry $labels) {}

    /** Manual/explicit outcome recording (Section 40 — e.g. "inspection performed", "maintenance completed"). */
    public function recordOutcome(string $tenantId, string $predictionId, string $entityType, string $entityId, string $outcomeType, CarbonImmutable $occurredAt, array $details = []): void
    {
        DB::connection('mongodb')->table('intelligence_outcomes')->insert([
            'tenant_id' => $tenantId,
            'prediction_id' => $predictionId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'outcome_type' => $outcomeType,
            'occurred_at' => $occurredAt->toIso8601String(),
            'details' => $details,
            'recorded_at' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }

    /**
     * Automatic sweep: for every vehicle_failure_risk (etc.) prediction
     * whose horizon has elapsed and that has no recorded outcome yet,
     * compute the actual ground truth via the same LabelBuilder used at
     * training time and record it. Returns how many were evaluated.
     */
    public function evaluateMaturedPredictions(string $tenantId, string $target): int
    {
        if (! $this->labels->has($target)) {
            return 0;
        }
        $labelBuilder = $this->labels->get($target);
        $now = CarbonImmutable::now();

        $predictions = DB::connection('mongodb')->table('intelligence_predictions')
            ->where('tenant_id', $tenantId)->where('prediction_type', $target)->get();

        $evaluated = 0;
        foreach ($predictions as $prediction) {
            $prediction = (array) $prediction;
            $predictionId = (string) $prediction['id'];

            $alreadyRecorded = DB::connection('mongodb')->table('intelligence_outcomes')
                ->where('prediction_id', $predictionId)->exists();
            if ($alreadyRecorded) {
                continue;
            }

            $featureDate = CarbonImmutable::parse($prediction['source_data_as_of']);
            $actualLabel = $labelBuilder->label($tenantId, $prediction['entity_id'], $featureDate, $now);
            if ($actualLabel === null) {
                continue; // horizon still hasn't elapsed
            }

            $predictedPositive = ($prediction['risk_level'] ?? null) !== null
                && in_array($prediction['risk_level'], ['HIGH', 'CRITICAL'], true);

            $this->recordOutcome($tenantId, $predictionId, $prediction['entity_type'], $prediction['entity_id'], 'MATURED_EVALUATION', $now, [
                'predicted_risk_level' => $prediction['risk_level'] ?? null,
                'predicted_positive' => $predictedPositive,
                'actual_positive' => $actualLabel,
                'correct' => $predictedPositive === $actualLabel,
                'source' => $prediction['source'] ?? null,
                'model_id' => $prediction['model_id'] ?? null,
            ]);
            $evaluated++;
        }

        return $evaluated;
    }
}
