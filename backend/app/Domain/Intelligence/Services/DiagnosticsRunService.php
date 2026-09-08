<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Analytics\Support\AnalyticsUpsertWriter;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Domain\Intelligence\Diagnostics\AnomalyDetectionService;
use App\Domain\Intelligence\Diagnostics\RepeatFailureDetectionService;
use App\Domain\Intelligence\Risk\RiskLevelCalculator;
use App\Domain\Intelligence\Rul\IntervalBasedRulService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 Section 24-27: RUL, repeat-failure, and anomaly diagnostics
 * for one tenant/business-date, all written into intelligence_predictions
 * (Section 36's generic prediction storage — Section 57's history/
 * freshness handling applies to these the same way it does to risk
 * scores, without a parallel collection per insight type).
 */
class DiagnosticsRunService
{
    public function __construct(
        private readonly IntervalBasedRulService $rul,
        private readonly RepeatFailureDetectionService $repeatFailures,
        private readonly AnomalyDetectionService $anomalies,
        private readonly RiskLevelCalculator $riskLevels,
        private readonly AnalyticsUpsertWriter $writer,
    ) {}

    public function runForTenant(string $tenantId, string $businessDate): array
    {
        $vehicleDocs = DB::connection('mongodb')->table('vehicle_daily_features')
            ->where('tenant_id', $tenantId)->where('feature_date', $businessDate)->get()
            ->map(fn ($d) => (array) $d)->all();
        $tireDocs = DB::connection('mongodb')->table('tire_daily_features')
            ->where('tenant_id', $tenantId)->where('feature_date', $businessDate)->get()
            ->map(fn ($d) => (array) $d)->all();

        $writes = [];
        $asOf = CarbonImmutable::createFromFormat('Y-m-d', $businessDate, 'UTC')->endOfDay();

        foreach ($vehicleDocs as $doc) {
            $writes = array_merge($writes, $this->vehicleRulWrites($tenantId, $doc, $businessDate));
            $writes = array_merge($writes, $this->repeatFailureWrites($tenantId, $doc, $asOf, $businessDate));
        }
        foreach ($tireDocs as $doc) {
            $writes = array_merge($writes, $this->tireRulWrites($tenantId, $doc, $businessDate));
        }
        $writes = array_merge($writes, $this->anomalyWrites($tenantId, $vehicleDocs, $businessDate));

        $result = new EtlDatasetResult;
        $this->writer->upsertMany('intelligence_predictions', $writes, $result);

        return ['written' => count($writes)];
    }

    private function vehicleRulWrites(string $tenantId, array $doc, string $businessDate): array
    {
        $rul = $this->rul->forVehicle($doc);
        if ($rul === null) {
            return [];
        }

        return [$this->doc($tenantId, 'vehicle', $doc['vehicle_id'], 'vehicle_rul', null, $rul['urgency'], $rul['explanation'], $doc, $businessDate, ['rul' => $rul])];
    }

    private function tireRulWrites(string $tenantId, array $doc, string $businessDate): array
    {
        $rul = $this->rul->forTire($doc);

        return [$this->doc($tenantId, 'tire', $doc['tire_id'], 'tire_rul', null, $rul['urgency'], $rul['explanation'], $doc, $businessDate, ['rul' => $rul, 'vehicle_id' => $doc['vehicle_id'] ?? null])];
    }

    private function repeatFailureWrites(string $tenantId, array $doc, CarbonImmutable $asOf, string $businessDate): array
    {
        $groups = $this->repeatFailures->detectForVehicle($doc['vehicle_id'], $asOf);
        $minOccurrences = max(1, (int) config('intelligence.repeat_failure.min_occurrences', 3));

        $writes = [];
        foreach ($groups as $group) {
            $score = min(1.0, $group['occurrences'] / $minOccurrences / 2);
            $entityId = $doc['vehicle_id'].':'.$group['component_group_id'];
            $explanation = ["{$group['occurrences']} repairs on the same component group between {$group['first_at']} and {$group['last_at']}."];
            $writes[] = $this->doc($tenantId, 'vehicle', $entityId, 'repeat_failure', $score, $this->riskLevels->forScore($score), $explanation, $doc, $businessDate, [
                'vehicle_id' => $doc['vehicle_id'], 'component_group_id' => $group['component_group_id'], 'occurrences' => $group['occurrences'],
            ]);
        }

        return $writes;
    }

    private function anomalyWrites(string $tenantId, array $vehicleDocs, string $businessDate): array
    {
        $anomalies = $this->anomalies->detectForFleet($vehicleDocs);
        $docsByVehicle = collect($vehicleDocs)->keyBy('vehicle_id');

        $writes = [];
        foreach ($anomalies as $vehicleId => $result) {
            $doc = $docsByVehicle[$vehicleId];
            $hasData = $result['data_anomalies'] !== [];
            $maxZ = collect($result['operational_anomalies'])->max('z_score') ?? 0;
            $riskLevel = $hasData ? 'DATA_QUALITY' : ($maxZ >= 4 ? 'HIGH' : 'MEDIUM');
            $explanation = array_merge(
                array_map(fn ($a) => "{$a['field']}: {$a['reason']} (value={$a['value']}).", $result['data_anomalies']),
                array_map(fn ($a) => "{$a['metric']} is unusual relative to the fleet (value={$a['value']}, fleet mean={$a['fleet_mean']}, z={$a['z_score']}).", $result['operational_anomalies']),
            );

            $writes[] = $this->doc($tenantId, 'vehicle', $vehicleId, 'anomaly', null, $riskLevel, $explanation, $doc, $businessDate, [
                'anomaly_type' => $hasData ? 'DATA_ANOMALY' : 'OPERATIONAL_ANOMALY',
                'data_anomalies' => $result['data_anomalies'], 'operational_anomalies' => $result['operational_anomalies'],
            ]);
        }

        return $writes;
    }

    private function doc(string $tenantId, string $entityType, string $entityId, string $predictionType, ?float $score, string $riskLevel, array $explanation, array $sourceDoc, string $businessDate, array $extra = []): array
    {
        $asOf = $sourceDoc['source_data_as_of'] ?? CarbonImmutable::now()->toIso8601String();

        return [
            'key' => ['tenant_id' => $tenantId, 'entity_type' => $entityType, 'entity_id' => $entityId, 'prediction_type' => $predictionType, 'horizon_days' => 0, 'source_data_as_of' => $asOf],
            'doc' => array_merge([
                'tenant_id' => $tenantId, 'entity_type' => $entityType, 'entity_id' => $entityId,
                'prediction_type' => $predictionType,
                'insight_level' => config("intelligence.insight_levels.{$predictionType}", 'DIAGNOSTIC'),
                'business_date' => $businessDate,
                'horizon_days' => 0, 'score' => $score, 'probability' => null, 'risk_level' => $riskLevel,
                'confidence' => 'MEDIUM', 'explanation' => $explanation, 'contributing_factors' => [],
                'source' => 'STATISTICAL', 'model_id' => null, 'model_version' => null,
                'feature_set_version' => $sourceDoc['feature_set_version'] ?? null,
                'source_data_as_of' => $asOf, 'predicted_at' => CarbonImmutable::now()->toIso8601String(),
                'expires_at' => null, 'freshness' => 'FRESH',
            ], $extra),
        ];
    }
}
