<?php

namespace App\Domain\Intelligence\Risk;

/**
 * Phase 7 Section 19: confidence is kept strictly separate from risk —
 * a HIGH risk score computed from sparse/missing data still deserves a
 * LOW/MEDIUM confidence label, and a rule-based/statistical score never
 * receives the same confidence ceiling as a calibrated ML model.
 */
class ConfidenceCalculator
{
    public function calculate(string $source, float $missingnessRatio, ?string $trainingReadinessStatus = null): string
    {
        $score = 1.0 - (0.5 * $missingnessRatio);

        if ($source !== 'ML_MODEL') {
            $score -= 0.25;
        }
        if ($trainingReadinessStatus === 'LIMITED') {
            $score -= 0.2;
        }
        $score = max(0.0, min(1.0, $score));

        $thresholds = config('intelligence.confidence_thresholds');
        arsort($thresholds);
        foreach ($thresholds as $level => $min) {
            if ($score >= $min) {
                return $level;
            }
        }

        return array_key_last($thresholds);
    }
}
