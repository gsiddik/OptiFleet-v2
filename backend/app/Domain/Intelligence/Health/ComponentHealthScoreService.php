<?php

namespace App\Domain\Intelligence\Health;

/**
 * Phase 7 Section 23: per (vehicle, component_group) health score from
 * the same deterministic point-deduction family as vehicle health
 * (Section 21) and Phase 6's original vehicle_health_score — failure
 * history, replacement history, repair history, and usage all
 * contribute a documented, configurable number of points.
 */
class ComponentHealthScoreService
{
    /** @param array $features a component_daily_features document */
    public function compute(array $features): array
    {
        $cfg = config('intelligence.component_health');

        $failureDeduction = (float) ($features['failure_count_90d'] ?? 0) * $cfg['points_per_failure'];
        $replacementDeduction = (float) ($features['replacement_count_90d'] ?? 0) * $cfg['points_per_replacement'];
        $repairDeduction = (float) ($features['repair_count_90d'] ?? 0) * $cfg['points_per_repair'];

        $usageKm = (float) ($features['usage_km'] ?? 0);
        $usageDeduction = min($cfg['usage_km_cap_points'], $usageKm / max(1, $cfg['usage_km_per_point']));

        $factors = [
            ['factor' => 'failure_count_90d', 'count' => $features['failure_count_90d'] ?? 0, 'points_deducted' => round($failureDeduction, 1)],
            ['factor' => 'replacement_count_90d', 'count' => $features['replacement_count_90d'] ?? 0, 'points_deducted' => round($replacementDeduction, 1)],
            ['factor' => 'repair_count_90d', 'count' => $features['repair_count_90d'] ?? 0, 'points_deducted' => round($repairDeduction, 1)],
            ['factor' => 'usage_km', 'count' => $usageKm, 'points_deducted' => round($usageDeduction, 1)],
        ];

        $totalDeducted = array_sum(array_column($factors, 'points_deducted'));
        $score = round(max(0, $cfg['base_score'] - $totalDeducted), 1);

        return [
            'score' => $score,
            'status' => $this->statusFor($score, $cfg['status_thresholds']),
            'factors' => $factors,
        ];
    }

    private function statusFor(float $score, array $thresholds): string
    {
        arsort($thresholds);
        foreach ($thresholds as $status => $min) {
            if ($score >= $min) {
                return $status;
            }
        }

        return array_key_last($thresholds);
    }
}
