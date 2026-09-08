<?php

namespace App\Domain\Intelligence\Monitoring;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 Section 49: data drift FOUNDATION — compares a recent window
 * of vehicle_daily_features against the immediately preceding window of
 * equal length for feature mean and missingness, per field. Does not
 * retrain anything itself (Section 49: "do not automatically retrain
 * solely because one threshold changed") — it only classifies.
 */
class DriftAssessmentService
{
    public function assess(string $tenantId, ?int $windowDays = null): array
    {
        $windowDays ??= (int) config('intelligence.monitoring.drift_window_days', 14);
        $fields = config('intelligence.model_feature_fields.vehicle', []);
        $now = CarbonImmutable::now();

        $recent = $this->windowStats($tenantId, $now->subDays($windowDays), $now, $fields);
        $prior = $this->windowStats($tenantId, $now->subDays($windowDays * 2), $now->subDays($windowDays), $fields);

        $watchZ = (float) config('intelligence.monitoring.drift_zscore_watch', 1.5);
        $driftedZ = (float) config('intelligence.monitoring.drift_zscore_drifted', 3.0);

        $perField = [];
        $worst = 'STABLE';
        foreach ($fields as $field) {
            if (($prior[$field]['count'] ?? 0) < 5 || ($recent[$field]['count'] ?? 0) < 5) {
                $perField[$field] = ['status' => 'INSUFFICIENT_DATA'];

                continue;
            }

            $priorStd = $prior[$field]['stddev'] ?: 1.0;
            $z = abs($recent[$field]['mean'] - $prior[$field]['mean']) / $priorStd;
            $status = $z >= $driftedZ ? 'DRIFTED' : ($z >= $watchZ ? 'WATCH' : 'STABLE');

            $perField[$field] = [
                'status' => $status, 'z_score' => round($z, 2),
                'recent_mean' => round($recent[$field]['mean'], 2), 'prior_mean' => round($prior[$field]['mean'], 2),
                'recent_missingness' => $recent[$field]['missingness'], 'prior_missingness' => $prior[$field]['missingness'],
            ];

            if ($status === 'DRIFTED') {
                $worst = 'DRIFTED';
            } elseif ($status === 'WATCH' && $worst !== 'DRIFTED') {
                $worst = 'WATCH';
            }
        }

        return ['overall_status' => $worst, 'window_days' => $windowDays, 'fields' => $perField, 'assessed_at' => $now->toIso8601String()];
    }

    private function windowStats(string $tenantId, CarbonImmutable $from, CarbonImmutable $to, array $fields): array
    {
        $docs = DB::connection('mongodb')->table('vehicle_daily_features')
            ->where('tenant_id', $tenantId)
            ->where('feature_date', '>=', $from->format('Y-m-d'))->where('feature_date', '<', $to->format('Y-m-d'))
            ->get();

        $stats = [];
        foreach ($fields as $field) {
            $values = $docs->pluck($field)->filter(fn ($v) => $v !== null)->map(fn ($v) => (float) $v);
            $count = $values->count();
            $mean = $count > 0 ? $values->avg() : 0.0;
            $variance = $count > 0 ? $values->map(fn ($v) => ($v - $mean) ** 2)->avg() : 0.0;

            $stats[$field] = [
                'count' => $count, 'mean' => $mean, 'stddev' => sqrt($variance),
                'missingness' => $docs->count() > 0 ? round(($docs->count() - $count) / $docs->count(), 3) : 0.0,
            ];
        }

        return $stats;
    }
}
