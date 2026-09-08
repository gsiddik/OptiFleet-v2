<?php

namespace App\Domain\Intelligence\Risk;

/**
 * Phase 7 Section 18: deterministic probability/score -> risk level
 * mapping, kept in one server-side place (config('intelligence.
 * risk_thresholds')) rather than scattered in frontend code.
 */
class RiskLevelCalculator
{
    public function forScore(float $score): string
    {
        $thresholds = config('intelligence.risk_thresholds');
        arsort($thresholds);
        foreach ($thresholds as $level => $min) {
            if ($score >= $min) {
                return $level;
            }
        }

        return array_key_last($thresholds);
    }
}
