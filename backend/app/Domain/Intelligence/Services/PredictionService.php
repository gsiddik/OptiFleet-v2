<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Analytics\Support\AnalyticsUpsertWriter;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Domain\Intelligence\Ml\LogisticRegression;
use App\Domain\Intelligence\Models\IntelligenceModel;
use App\Domain\Intelligence\Risk\ConfidenceCalculator;
use App\Domain\Intelligence\Risk\RiskLevelCalculator;
use App\Domain\Intelligence\Risk\RiskScorerRegistry;
use Carbon\CarbonImmutable;

/**
 * Phase 7 Section 10, 17-20: produces one prediction document. Always
 * prefers an ACTIVE TENANT-scope model, falling back to an ACTIVE
 * GLOBAL-scope model, and finally to the deterministic/statistical
 * scorer (Section 8) when neither exists — a prediction is never
 * refused for lack of a trained model. The `source` field
 * (ML_MODEL|RULE_BASED|STATISTICAL) is never ambiguous to a caller
 * (Section 8: never present rule-based output as an ML prediction).
 */
class PredictionService
{
    public const SOURCE_ML = 'ML_MODEL';

    public const SOURCE_RULE_BASED = 'RULE_BASED';

    public function __construct(
        private readonly ModelRegistryService $registry,
        private readonly RiskScorerRegistry $scorers,
        private readonly RiskLevelCalculator $riskLevels,
        private readonly ConfidenceCalculator $confidence,
        private readonly AnalyticsUpsertWriter $writer,
    ) {}

    public function predict(string $tenantId, string $entityType, string $entityId, string $target, array $features, CarbonImmutable $sourceDataAsOf): array
    {
        $targetConfig = config("intelligence.model_targets.{$target}");
        $horizonDays = $targetConfig['horizon_days'] ?? 0;

        $model = $this->registry->active($target, IntelligenceModel::SCOPE_TENANT, $tenantId)
            ?? $this->registry->active($target, IntelligenceModel::SCOPE_GLOBAL, null);

        if ($model) {
            [$score, $factors, $readinessStatus] = $this->predictWithModel($model, $features);
            $source = self::SOURCE_ML;
            $modelId = (string) $model->id;
            $modelVersion = $model->version;
        } else {
            $scorer = $this->scorers->get($target);
            $result = $scorer->score($features);
            $score = $result['score'];
            $factors = $result['factors'];
            $readinessStatus = null;
            $source = self::SOURCE_RULE_BASED;
            $modelId = null;
            $modelVersion = null;
        }

        $riskLevel = $this->riskLevels->forScore($score);
        $coreFields = config("intelligence.core_numeric_fields.{$entityType}", []);
        $missingness = $this->missingnessRatio($features, $coreFields);
        $confidenceLevel = $this->confidence->calculate($source, $missingness, $readinessStatus);
        $explanation = $this->buildExplanation($factors);

        $now = CarbonImmutable::now();
        $staleAfter = $sourceDataAsOf->addHours((int) config('intelligence.monitoring.stale_after_hours', 48));
        $expiresAfter = $sourceDataAsOf->addHours((int) config('intelligence.monitoring.expires_after_hours', 168));

        $doc = [
            'tenant_id' => $tenantId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'prediction_type' => $target,
            'insight_level' => config("intelligence.insight_levels.{$target}", 'PREDICTIVE'),
            'horizon_days' => $horizonDays,
            'score' => $score,
            'probability' => $source === self::SOURCE_ML ? $score : null,
            'risk_level' => $riskLevel,
            'confidence' => $confidenceLevel,
            'explanation' => $explanation,
            'contributing_factors' => $factors,
            'source' => $source,
            'model_id' => $modelId,
            'model_version' => $modelVersion,
            'feature_set_version' => $features['feature_set_version'] ?? null,
            'source_data_as_of' => $sourceDataAsOf->toIso8601String(),
            'predicted_at' => $now->toIso8601String(),
            'expires_at' => $expiresAfter->toIso8601String(),
            'freshness' => $now->isAfter($expiresAfter) ? 'EXPIRED' : ($now->isAfter($staleAfter) ? 'STALE' : 'FRESH'),
        ];

        $result = new EtlDatasetResult;
        $this->writer->upsertMany('intelligence_predictions', [[
            'key' => [
                'tenant_id' => $tenantId, 'entity_type' => $entityType, 'entity_id' => $entityId,
                'prediction_type' => $target, 'horizon_days' => $horizonDays, 'source_data_as_of' => $sourceDataAsOf->toIso8601String(),
            ],
            'doc' => $doc,
        ]], $result);

        return $doc;
    }

    private function predictWithModel(IntelligenceModel $model, array $features): array
    {
        $regression = LogisticRegression::fromArtifact($model->artifact);
        $probability = $regression->predictProba($features);
        $contributions = $regression->contributions($features);

        $factors = [];
        foreach ($contributions as $name => $value) {
            $factors[] = ['factor' => $name, 'count' => $features[$name] ?? null, 'weight' => null, 'contribution' => round($value, 4)];
        }

        return [$probability, $factors, $model->business_metrics['readiness_status'] ?? null];
    }

    private function missingnessRatio(array $features, array $coreFields): float
    {
        if ($coreFields === []) {
            return 0.0;
        }
        $missing = 0;
        foreach ($coreFields as $field) {
            if (($features[$field] ?? null) === null) {
                $missing++;
            }
        }

        return $missing / count($coreFields);
    }

    /** @return string[] */
    private function buildExplanation(array $factors): array
    {
        $labels = config('intelligence.factor_labels', []);
        $top = array_slice($factors, 0, 5);
        $lines = [];
        foreach ($top as $factor) {
            if (($factor['contribution'] ?? 0) == 0) {
                continue;
            }
            $template = $labels[$factor['factor']] ?? ($factor['factor'].': {count}');
            $lines[] = str_replace('{count}', (string) ($factor['count'] ?? '—'), $template);
        }

        return $lines ?: ['No significant contributing factors identified.'];
    }
}
