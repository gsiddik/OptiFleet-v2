<?php

namespace App\Domain\Intelligence\Ml;

/**
 * Phase 7 Section 14: classification evaluation metrics computed
 * directly (no ML library dependency) from predicted probabilities vs
 * actual 0/1 labels on a held-out test set.
 */
class ModelEvaluator
{
    /**
     * @param  float[]  $probabilities
     * @param  int[]  $labels
     */
    public function evaluate(array $probabilities, array $labels, float $threshold = 0.5): array
    {
        $tp = $fp = $tn = $fn = 0;
        foreach ($probabilities as $i => $p) {
            $predicted = $p >= $threshold ? 1 : 0;
            $actual = $labels[$i];
            match (true) {
                $predicted === 1 && $actual === 1 => $tp++,
                $predicted === 1 && $actual === 0 => $fp++,
                $predicted === 0 && $actual === 0 => $tn++,
                default => $fn++,
            };
        }

        $precision = ($tp + $fp) > 0 ? $tp / ($tp + $fp) : 0.0;
        $recall = ($tp + $fn) > 0 ? $tp / ($tp + $fn) : 0.0;
        $f1 = ($precision + $recall) > 0 ? 2 * $precision * $recall / ($precision + $recall) : 0.0;

        return [
            'precision' => round($precision, 4),
            'recall' => round($recall, 4),
            'f1' => round($f1, 4),
            'roc_auc' => round($this->rocAuc($probabilities, $labels), 4),
            'confusion_matrix' => ['tp' => $tp, 'fp' => $fp, 'tn' => $tn, 'fn' => $fn],
            'threshold' => $threshold,
        ];
    }

    /** Mann-Whitney U based ROC-AUC — exact, no external library needed. */
    private function rocAuc(array $probabilities, array $labels): float
    {
        $positives = [];
        $negatives = [];
        foreach ($probabilities as $i => $p) {
            $labels[$i] === 1 ? $positives[] = $p : $negatives[] = $p;
        }

        if ($positives === [] || $negatives === []) {
            return 0.5; // undefined with only one class present in the test set
        }

        $concordant = 0.0;
        foreach ($positives as $pos) {
            foreach ($negatives as $neg) {
                if ($pos > $neg) {
                    $concordant += 1.0;
                } elseif ($pos === $neg) {
                    $concordant += 0.5;
                }
            }
        }

        return $concordant / (count($positives) * count($negatives));
    }
}
