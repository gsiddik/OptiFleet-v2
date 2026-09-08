<?php

namespace App\Domain\Intelligence\Health;

/**
 * Phase 7 Section 21-22: extends Phase 6's deterministic
 * vehicle_health_score (a single point-deduction number) into a
 * governed, subscore-broken-down intelligence score. Every subscore is
 * itself 0-100 (higher = healthier) and independently explainable; the
 * blend weights live in config('intelligence.health_score.weights'),
 * never hardcoded in a controller or the frontend (Section 22).
 *
 * predictive_risk is the one subscore that is genuinely new relative to
 * Phase 6 — it comes from the Phase 7 vehicle_failure_risk score
 * (whichever source produced it, ML or RULE_BASED; the health score
 * itself makes no ML claim either way).
 */
class VehicleHealthScoreService
{
    /**
     * @param  array  $features  a vehicle_daily_features document
     * @param  float  $failureRiskScore  0-1, from PredictionService (either source)
     */
    public function compute(array $features, float $failureRiskScore): array
    {
        $subscores = [
            'maintenance_compliance' => $this->deduct(100, (float) ($features['overdue_maintenance_count'] ?? 0), 20),
            'breakdown_history' => $this->deduct(100, (float) ($features['breakdown_count_90d'] ?? 0), 15),
            'inspection_health' => $this->deduct(100, (float) ($features['critical_inspection_finding_count_90d'] ?? 0), 25),
            'component_reliability' => $this->deduct(100, (float) ($features['component_replacement_count_90d'] ?? 0), 20),
            'downtime' => $this->deduct(100, round((float) ($features['downtime_minutes_90d'] ?? 0) / 1440, 2), 10),
            'tire_condition' => $this->deduct(100, (float) ($features['tire_replacement_count_90d'] ?? 0), 20),
            'predictive_risk' => round(100 - ($failureRiskScore * 100), 1),
        ];

        $weights = config('intelligence.health_score.weights');
        $overall = 0.0;
        foreach ($weights as $key => $weight) {
            $overall += ($subscores[$key] ?? 100) * $weight;
        }
        $overall = round(max(0, min(100, $overall)), 1);

        return [
            'score' => $overall,
            'status' => $this->statusFor($overall),
            'subscores' => $subscores,
        ];
    }

    private function deduct(float $base, float $count, float $pointsEach): float
    {
        return round(max(0, $base - ($count * $pointsEach)), 1);
    }

    private function statusFor(float $score): string
    {
        $thresholds = config('intelligence.health_score.status_thresholds');
        arsort($thresholds);
        foreach ($thresholds as $status => $min) {
            if ($score >= $min) {
                return $status;
            }
        }

        return array_key_last($thresholds);
    }
}
