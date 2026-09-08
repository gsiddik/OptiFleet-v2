<?php

namespace App\Domain\Intelligence\Diagnostics;

/**
 * Phase 7 Section 26: cross-sectional statistical anomaly detection —
 * compares each vehicle's metrics against that day's fleet-wide
 * distribution (z-score), not a per-vehicle time series (Phase 1-5 has
 * too little per-vehicle history for that to be meaningful yet).
 *
 * DATA_ANOMALY: the value itself is impossible (e.g. negative usage) —
 * a data-quality problem, not a fleet/operational signal.
 * OPERATIONAL_ANOMALY: the value is plausible but statistically unusual
 * relative to the rest of the fleet. Neither classification implies
 * fault (Section 26: "do not automatically conclude fraud or operator
 * fault") — the explanation states only the statistical fact.
 */
class AnomalyDetectionService
{
    private const METRICS = ['breakdown_count_90d', 'downtime_minutes_90d', 'component_replacement_count_90d', 'tire_replacement_count_90d'];

    /**
     * @param  array<int, array>  $fleetFeatureDocs  all vehicle_daily_features docs for one tenant/date
     * @return array<string, array> vehicle_id => anomaly result (only vehicles with at least one flagged metric)
     */
    public function detectForFleet(array $fleetFeatureDocs): array
    {
        if (count($fleetFeatureDocs) < (int) config('intelligence.anomaly.min_history_points', 10)) {
            return []; // not enough fleet breadth for a statistically meaningful comparison
        }

        $threshold = (float) config('intelligence.anomaly.zscore_threshold', 2.5);
        $stats = $this->fleetStats($fleetFeatureDocs);

        $results = [];
        foreach ($fleetFeatureDocs as $doc) {
            $dataAnomalies = $this->dataAnomalies($doc);
            $operationalAnomalies = [];

            foreach (self::METRICS as $metric) {
                if (($stats[$metric]['stddev'] ?? 0) <= 0) {
                    continue;
                }
                $value = (float) ($doc[$metric] ?? 0);
                $z = ($value - $stats[$metric]['mean']) / $stats[$metric]['stddev'];
                if (abs($z) >= $threshold) {
                    $operationalAnomalies[] = ['metric' => $metric, 'value' => $value, 'fleet_mean' => round($stats[$metric]['mean'], 2), 'z_score' => round($z, 2)];
                }
            }

            if ($dataAnomalies !== [] || $operationalAnomalies !== []) {
                $results[$doc['vehicle_id']] = ['data_anomalies' => $dataAnomalies, 'operational_anomalies' => $operationalAnomalies];
            }
        }

        return $results;
    }

    private function dataAnomalies(array $doc): array
    {
        $flags = [];
        foreach (['km_per_day', 'current_odometer', 'breakdown_count_90d', 'downtime_minutes_90d'] as $field) {
            if (isset($doc[$field]) && (float) $doc[$field] < 0) {
                $flags[] = ['field' => $field, 'value' => $doc[$field], 'reason' => 'negative value is not physically possible'];
            }
        }

        return $flags;
    }

    private function fleetStats(array $docs): array
    {
        $stats = [];
        $n = count($docs);
        foreach (self::METRICS as $metric) {
            $values = array_map(fn ($d) => (float) ($d[$metric] ?? 0), $docs);
            $mean = array_sum($values) / $n;
            $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $values)) / $n;
            $stats[$metric] = ['mean' => $mean, 'stddev' => sqrt($variance)];
        }

        return $stats;
    }
}
