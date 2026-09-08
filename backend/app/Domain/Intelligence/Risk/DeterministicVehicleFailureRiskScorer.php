<?php

namespace App\Domain\Intelligence\Risk;

/**
 * Phase 7 Section 8, 17 example in spec: "3 breakdowns in 30 days +
 * overdue maintenance + critical inspection finding -> HIGH RISK".
 * Simple weighted-linear score capped at 1.0, fully explainable via the
 * same factor list every predictive output must return (Section 20).
 */
class DeterministicVehicleFailureRiskScorer implements RiskScorer
{
    public function target(): string
    {
        return 'vehicle_failure_risk';
    }

    public function score(array $features): array
    {
        $weights = config('intelligence.rule_based_risk.vehicle_failure_risk');

        $counts = [
            'overdue_maintenance_count' => (float) ($features['overdue_maintenance_count'] ?? 0),
            'breakdown_count_90d' => (float) ($features['breakdown_count_90d'] ?? 0),
            'repeat_repair_count_90d' => (float) ($features['repeat_repair_count_90d'] ?? 0),
            'critical_inspection_finding_count_90d' => (float) ($features['critical_inspection_finding_count_90d'] ?? 0),
            'component_replacement_count_90d' => (float) ($features['component_replacement_count_90d'] ?? 0),
            'tire_replacement_count_90d' => (float) ($features['tire_replacement_count_90d'] ?? 0),
            'downtime_days_90d' => round((float) ($features['downtime_minutes_90d'] ?? 0) / 1440, 2),
        ];

        $factors = [];
        $total = 0.0;
        foreach ($counts as $name => $count) {
            $weight = $weights[$name] ?? 0.0;
            $contribution = $count * $weight;
            $total += $contribution;
            $factors[] = ['factor' => $name, 'count' => $count, 'weight' => $weight, 'contribution' => round($contribution, 4)];
        }

        usort($factors, fn ($a, $b) => $b['contribution'] <=> $a['contribution']);

        return ['score' => min(1.0, round($total, 4)), 'factors' => $factors];
    }
}
