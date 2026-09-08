<?php

namespace App\Domain\Intelligence\DataReadiness;

use App\Domain\Intelligence\Labels\LabelBuilderRegistry;

/**
 * Phase 7 Section 7: computed before any training attempt. Never trains
 * a misleading model on insufficient data (Section 71) — a NOT_READY or
 * LIMITED result is a hard/soft gate the training pipeline (Batch C)
 * must respect.
 */
class DataReadinessAssessmentService
{
    public function __construct(
        private readonly TrainingDatasetBuilder $datasetBuilder,
        private readonly LabelBuilderRegistry $labels,
    ) {}

    public function assess(string $tenantId, string $target): DataReadinessResult
    {
        $rows = $this->datasetBuilder->build($tenantId, $target);
        $entityType = $this->labels->get($target)->entityType();
        $coreFields = config("intelligence.core_numeric_fields.{$entityType}", []);
        $cfg = config('intelligence.data_readiness');

        $sampleSize = count($rows);
        $positiveCount = count(array_filter($rows, fn (TrainingRow $r) => $r->label));
        $majorityClassRatio = $sampleSize > 0 ? max($positiveCount, $sampleSize - $positiveCount) / $sampleSize : 1.0;

        $missingness = $this->missingnessRatio($rows, $coreFields);
        $observationPeriodDays = $this->observationPeriodDays($rows);

        $reasons = [];
        $status = DataReadinessResult::READY;

        if ($sampleSize < $cfg['limited_sample_size'] || $positiveCount < 1) {
            $status = DataReadinessResult::NOT_READY;
            $reasons[] = "sample_size={$sampleSize} below minimum viable {$cfg['limited_sample_size']}, or zero positive events.";
        } elseif ($sampleSize < $cfg['min_sample_size']) {
            $status = DataReadinessResult::LIMITED;
            $reasons[] = "sample_size={$sampleSize} below target minimum {$cfg['min_sample_size']}.";
        }

        if ($status !== DataReadinessResult::NOT_READY) {
            if ($positiveCount < $cfg['min_positive_count']) {
                $status = DataReadinessResult::LIMITED;
                $reasons[] = "positive_count={$positiveCount} below target minimum {$cfg['min_positive_count']}.";
            }
            if ($observationPeriodDays < $cfg['min_observation_period_days']) {
                $status = DataReadinessResult::LIMITED;
                $reasons[] = "observation_period_days={$observationPeriodDays} below minimum {$cfg['min_observation_period_days']}.";
            }
            if ($majorityClassRatio > $cfg['max_majority_class_ratio']) {
                $status = DataReadinessResult::LIMITED;
                $reasons[] = 'class imbalance too severe: majority class ratio '.round($majorityClassRatio, 3);
            }
            if ($missingness > $cfg['max_missingness_ratio']) {
                $status = DataReadinessResult::LIMITED;
                $reasons[] = 'feature missingness too high: '.round($missingness, 3);
            }
        }

        if ($reasons === []) {
            $reasons[] = 'all readiness criteria satisfied.';
        }

        return new DataReadinessResult($status, $sampleSize, $positiveCount, $missingness, $observationPeriodDays, $majorityClassRatio, $reasons);
    }

    /** @param TrainingRow[] $rows */
    private function missingnessRatio(array $rows, array $coreFields): float
    {
        if ($rows === [] || $coreFields === []) {
            return 0.0;
        }

        $totalCells = count($rows) * count($coreFields);
        $missingCells = 0;
        foreach ($rows as $row) {
            foreach ($coreFields as $field) {
                if (($row->features[$field] ?? null) === null) {
                    $missingCells++;
                }
            }
        }

        return $totalCells > 0 ? $missingCells / $totalCells : 0.0;
    }

    /** @param TrainingRow[] $rows */
    private function observationPeriodDays(array $rows): int
    {
        if ($rows === []) {
            return 0;
        }

        $dates = array_map(fn (TrainingRow $r) => $r->featureDate, $rows);
        sort($dates);

        return (int) (strtotime(end($dates)) - strtotime($dates[0])) / 86400;
    }
}
