<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Intelligence\DataReadiness\DataReadinessAssessmentService;
use App\Domain\Intelligence\DataReadiness\DataReadinessResult;
use App\Domain\Intelligence\DataReadiness\TrainingDatasetBuilder;
use App\Domain\Intelligence\DataReadiness\TrainingRow;
use App\Domain\Intelligence\Ml\LogisticRegression;
use App\Domain\Intelligence\Ml\ModelEvaluator;
use App\Domain\Intelligence\Models\IntelligenceModel;

/**
 * Phase 7 Section 42, 66-67: Dataset Selection -> Data Readiness ->
 * Feature Extraction -> Train -> Evaluate -> Register Model. Never runs
 * inside an HTTP request (Section 42 — always called from a queued job,
 * see TrainModelJob); this class itself is transport-agnostic.
 *
 * Temporal validation (Section 66): rows are split by feature_date, not
 * randomly — the earliest 70% of dates train, the latest 30% test, so
 * evaluation always happens on a strictly later period than training,
 * never a random shuffle that could leak future information into the
 * training set.
 */
class TrainingPipelineService
{
    private const TRAIN_SPLIT_RATIO = 0.7;

    public function __construct(
        private readonly DataReadinessAssessmentService $readiness,
        private readonly TrainingDatasetBuilder $datasetBuilder,
        private readonly ModelRegistryService $registry,
        private readonly ModelEvaluator $evaluator,
    ) {}

    public function train(string $tenantId, string $target, string $scope = IntelligenceModel::SCOPE_TENANT, string $createdBy = 'system'): IntelligenceModel
    {
        $targetConfig = config("intelligence.model_targets.{$target}")
            ?? throw new \InvalidArgumentException("Unknown model target [{$target}].");

        $modelTenantId = $scope === IntelligenceModel::SCOPE_TENANT ? $tenantId : null;
        $model = $this->registry->createDraft([
            'model_code' => $target,
            'model_type' => 'classification',
            'target' => $target,
            'entity_type' => $targetConfig['entity_type'],
            'algorithm' => $targetConfig['algorithm'],
            'feature_set_version' => config('intelligence.feature_set_versions.'.$targetConfig['entity_type'], 'v1'),
            'scope' => $scope,
            'tenant_id' => $modelTenantId,
            'created_by' => $createdBy,
            'acceptance_criteria' => [
                'precision' => $targetConfig['min_precision'] ?? 0.0,
                'recall' => $targetConfig['min_recall'] ?? 0.0,
            ],
        ]);

        $readinessResult = $this->readiness->assess($tenantId, $target);

        if ($readinessResult->status === DataReadinessResult::NOT_READY) {
            return $this->registry->markFailed($model, 'Data readiness NOT_READY: '.implode(' ', $readinessResult->reasons));
        }

        $model = $this->registry->markTraining($model);
        $rows = $this->datasetBuilder->build($tenantId, $target);

        [$trainRows, $testRows] = $this->temporalSplit($rows);
        if (count($trainRows) < 5 || count($testRows) < 2 || $this->allSameLabel($testRows)) {
            return $this->registry->markFailed($model, 'Insufficient rows or no label variation after temporal train/test split.');
        }

        $featureFields = config("intelligence.model_feature_fields.{$targetConfig['entity_type']}", []);

        $regression = new LogisticRegression;
        $regression->fit(
            $featureFields,
            array_map(fn (TrainingRow $r) => $r->features, $trainRows),
            array_map(fn (TrainingRow $r) => $r->label ? 1 : 0, $trainRows),
        );

        $testProbabilities = array_map(fn (TrainingRow $r) => $regression->predictProba($r->features), $testRows);
        $testLabels = array_map(fn (TrainingRow $r) => $r->label ? 1 : 0, $testRows);
        $metrics = $this->evaluator->evaluate($testProbabilities, $testLabels);

        $businessMetrics = [
            'test_sample_size' => count($testRows),
            'test_positive_count' => array_sum($testLabels),
            'readiness_status' => $readinessResult->status,
        ];

        return $this->registry->recordEvaluation(
            $model,
            $metrics,
            $businessMetrics,
            $regression->toArtifact(),
            datasetSize: count($rows),
            positiveCount: count(array_filter($rows, fn (TrainingRow $r) => $r->label)),
        );
    }

    /** @return array{0: TrainingRow[], 1: TrainingRow[]} */
    private function temporalSplit(array $rows): array
    {
        usort($rows, fn (TrainingRow $a, TrainingRow $b) => $a->featureDate <=> $b->featureDate);
        $dates = array_values(array_unique(array_map(fn (TrainingRow $r) => $r->featureDate, $rows)));
        $splitIndex = max(1, (int) floor(count($dates) * self::TRAIN_SPLIT_RATIO));
        $cutoffDate = $dates[$splitIndex - 1];

        $train = array_values(array_filter($rows, fn (TrainingRow $r) => $r->featureDate <= $cutoffDate));
        $test = array_values(array_filter($rows, fn (TrainingRow $r) => $r->featureDate > $cutoffDate));

        return [$train, $test];
    }

    private function allSameLabel(array $rows): bool
    {
        $labels = array_unique(array_map(fn (TrainingRow $r) => $r->label, $rows));

        return count($labels) < 2;
    }
}
