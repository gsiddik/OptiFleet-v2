<?php

namespace App\Domain\Intelligence\Monitoring;

use App\Domain\Intelligence\Models\IntelligenceModel;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 Section 48 — model observability: prediction volume, average
 * confidence, risk distribution, and (once outcomes have matured,
 * Section 40-41) precision/recall computed directly from
 * intelligence_outcomes' MATURED_EVALUATION records — the same ground
 * truth OutcomeFeedbackService produced, not a second calculation.
 */
class ModelMonitoringService
{
    public function forModel(IntelligenceModel $model): array
    {
        $predictions = DB::connection('mongodb')->table('intelligence_predictions')
            ->where('model_id', (string) $model->id)->get();

        $confidenceScore = ['HIGH' => 1.0, 'MEDIUM' => 0.6, 'LOW' => 0.2];
        $avgConfidence = $predictions->isNotEmpty()
            ? round($predictions->avg(fn ($p) => $confidenceScore[$p->confidence] ?? 0.5), 3)
            : null;

        $outcomes = DB::connection('mongodb')->table('intelligence_outcomes')
            ->where('model_id', (string) $model->id)->where('outcome_type', 'MATURED_EVALUATION')->get();

        $detailsOf = fn ($o) => (array) $o->details;
        $tp = $outcomes->filter(fn ($o) => $detailsOf($o)['predicted_positive'] && $detailsOf($o)['actual_positive'])->count();
        $fp = $outcomes->filter(fn ($o) => $detailsOf($o)['predicted_positive'] && ! $detailsOf($o)['actual_positive'])->count();
        $fn = $outcomes->filter(fn ($o) => ! $detailsOf($o)['predicted_positive'] && $detailsOf($o)['actual_positive'])->count();

        return [
            'model_id' => (string) $model->id,
            'model_code' => $model->model_code,
            'version' => $model->version,
            'status' => $model->status,
            'prediction_count' => $predictions->count(),
            'average_confidence' => $avgConfidence,
            'risk_level_distribution' => $predictions->countBy('risk_level'),
            'matured_outcome_count' => $outcomes->count(),
            'precision_over_time' => ($tp + $fp) > 0 ? round($tp / ($tp + $fp), 3) : null,
            'recall_over_time' => ($tp + $fn) > 0 ? round($tp / ($tp + $fn), 3) : null,
            'false_positives' => $fp,
            'false_negatives' => $fn,
        ];
    }
}
