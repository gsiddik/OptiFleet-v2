<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Analytics\Support\AnalyticsUpsertWriter;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Domain\Intelligence\Health\ComponentHealthScoreService;
use App\Domain\Intelligence\Health\VehicleHealthScoreService;
use App\Domain\Intelligence\Risk\ConfidenceCalculator;
use App\Domain\Intelligence\Risk\RiskScorerRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 Section 21-23, 57: computes and persists governed vehicle and
 * component health scores, storing them as their own prediction_type in
 * intelligence_predictions (Section 57 "risk history" comes for free
 * from that collection's existing history/idempotency behavior — a
 * health score is not a risk *probability*, but reusing the same
 * append-by-business-date storage shape avoids a parallel collection).
 * predictive_risk (the one Section-21 subscore Phase 6 never had) is
 * read from that same run's just-computed vehicle_failure_risk
 * prediction when present, so the two stay consistent for a given day.
 */
class HealthScoreRunService
{
    public function __construct(
        private readonly VehicleHealthScoreService $vehicleHealth,
        private readonly ComponentHealthScoreService $componentHealth,
        private readonly RiskScorerRegistry $scorers,
        private readonly ConfidenceCalculator $confidence,
        private readonly AnalyticsUpsertWriter $writer,
    ) {}

    public function runForTenant(string $tenantId, string $businessDate): array
    {
        $vehicleCount = $this->runVehicleHealth($tenantId, $businessDate);
        $componentCount = $this->runComponentHealth($tenantId, $businessDate);

        return ['vehicle' => $vehicleCount, 'component' => $componentCount];
    }

    private function runVehicleHealth(string $tenantId, string $businessDate): int
    {
        $documents = DB::connection('mongodb')->table('vehicle_daily_features')
            ->where('tenant_id', $tenantId)->where('feature_date', $businessDate)->get();

        $latestPredictions = DB::connection('mongodb')->table('intelligence_predictions')
            ->where('tenant_id', $tenantId)->where('prediction_type', 'vehicle_failure_risk')
            ->where('source_data_as_of', 'like', $businessDate.'%')
            ->get()->keyBy('entity_id');

        $writes = [];
        foreach ($documents as $doc) {
            $doc = (array) $doc;
            $vehicleId = $doc['vehicle_id'];
            $riskScore = isset($latestPredictions[$vehicleId])
                ? (float) $latestPredictions[$vehicleId]->score
                : $this->scorers->get('vehicle_failure_risk')->score($doc)['score'];

            $result = $this->vehicleHealth->compute($doc, $riskScore);
            $coreFields = config('intelligence.core_numeric_fields.vehicle', []);
            $missing = $this->missingnessRatio($doc, $coreFields);

            $writes[] = $this->buildDoc($tenantId, 'vehicle', $vehicleId, 'vehicle_health_score', $result['score'], $result['status'],
                $this->explainSubscores($result['subscores']), $doc, $missing);
        }

        $this->write($writes);

        return count($writes);
    }

    private function runComponentHealth(string $tenantId, string $businessDate): int
    {
        $documents = DB::connection('mongodb')->table('component_daily_features')
            ->where('tenant_id', $tenantId)->where('feature_date', $businessDate)->get();

        $writes = [];
        foreach ($documents as $doc) {
            $doc = (array) $doc;
            $result = $this->componentHealth->compute($doc);
            $missing = $this->missingnessRatio($doc, config('intelligence.core_numeric_fields.component', []));

            $componentId = $doc['component_asset_id'] ?? $doc['vehicle_id'].':'.$doc['component_group_id'];
            $writes[] = $this->buildDoc($tenantId, 'component', $componentId, 'component_health_score', $result['score'], $result['status'],
                $this->explainFactors($result['factors']), $doc, $missing, ['vehicle_id' => $doc['vehicle_id'], 'component_group_id' => $doc['component_group_id']]);
        }

        $this->write($writes);

        return count($writes);
    }

    private function buildDoc(string $tenantId, string $entityType, string $entityId, string $predictionType, float $score, string $status, array $explanation, array $features, float $missingness, array $extra = []): array
    {
        $asOf = $features['source_data_as_of'] ?? CarbonImmutable::now()->toIso8601String();

        return [
            'key' => ['tenant_id' => $tenantId, 'entity_type' => $entityType, 'entity_id' => $entityId, 'prediction_type' => $predictionType, 'horizon_days' => 0, 'source_data_as_of' => $asOf],
            'doc' => array_merge([
                'tenant_id' => $tenantId, 'entity_type' => $entityType, 'entity_id' => $entityId,
                'prediction_type' => $predictionType,
                'insight_level' => config("intelligence.insight_levels.{$predictionType}", 'DESCRIPTIVE'),
                'horizon_days' => 0,
                'score' => $score, 'probability' => null, 'risk_level' => $status,
                'confidence' => $this->confidence->calculate('STATISTICAL', $missingness),
                'explanation' => $explanation, 'source' => 'STATISTICAL',
                'model_id' => null, 'model_version' => null,
                'feature_set_version' => $features['feature_set_version'] ?? null,
                'source_data_as_of' => $asOf, 'predicted_at' => CarbonImmutable::now()->toIso8601String(),
                'expires_at' => null, 'freshness' => 'FRESH',
            ], $extra),
        ];
    }

    private function write(array $writes): void
    {
        if ($writes === []) {
            return;
        }
        $result = new EtlDatasetResult;
        $this->writer->upsertMany('intelligence_predictions', $writes, $result);
    }

    private function missingnessRatio(array $features, array $coreFields): float
    {
        if ($coreFields === []) {
            return 0.0;
        }
        $missing = count(array_filter($coreFields, fn ($f) => ($features[$f] ?? null) === null));

        return $missing / count($coreFields);
    }

    private function explainSubscores(array $subscores): array
    {
        $lines = [];
        foreach ($subscores as $name => $value) {
            $lines[] = str_replace('_', ' ', $name).': '.$value.'/100';
        }

        return $lines;
    }

    private function explainFactors(array $factors): array
    {
        $lines = [];
        foreach ($factors as $factor) {
            if (($factor['points_deducted'] ?? 0) > 0) {
                $lines[] = str_replace('_', ' ', $factor['factor']).' = '.$factor['count'].' (-'.$factor['points_deducted'].' pts)';
            }
        }

        return $lines ?: ['No deductions — clean recent history.'];
    }
}
