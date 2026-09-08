<?php

namespace App\Domain\Intelligence\Services;

use App\Domain\Intelligence\Inventory\SparePartIntelligenceService;
use App\Domain\Intelligence\Models\IntelligenceRecommendation;
use App\Domain\Notification\Services\NotificationDispatchService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 Section 29-31, 35: deterministic prediction -> recommendation
 * mapping (config('intelligence.recommendation.rules')), one
 * recommendation per source prediction document (idempotent on
 * prediction_id, so a vehicle staying HIGH risk for a week doesn't spawn
 * seven duplicate open recommendations). Fires the Phase 5 notification
 * engine for HIGH/CRITICAL risk (Section 35), never a second
 * notification system.
 */
class RecommendationGenerationService
{
    public function __construct(
        private readonly SparePartIntelligenceService $spareParts,
        private readonly NotificationDispatchService $notifications,
    ) {}

    public function generateForTenant(string $tenantId, string $businessDate): int
    {
        $rules = config('intelligence.recommendation.rules', []);
        $predictions = DB::connection('mongodb')->table('intelligence_predictions')
            ->where('tenant_id', $tenantId)
            ->where('business_date', $businessDate)
            ->get();

        $created = 0;
        foreach ($predictions as $prediction) {
            $prediction = (array) $prediction;
            $type = $prediction['prediction_type'];
            $riskLevel = $prediction['risk_level'] ?? null;
            $rule = $rules[$type][$riskLevel] ?? null;
            if ($rule === null) {
                continue;
            }

            $predictionId = (string) $prediction['id'];
            if (IntelligenceRecommendation::query()->withoutGlobalScopes()->where('prediction_id', $predictionId)->exists()) {
                continue;
            }

            $evidence = ['prediction_type' => $type, 'risk_level' => $riskLevel, 'score' => $prediction['score'] ?? null, 'explanation' => $prediction['explanation'] ?? []];
            $suggestedParts = in_array($rule['type'], ['REPLACE_COMPONENT', 'REPLACE_TIRE'], true) && ($prediction['entity_type'] ?? null) === 'vehicle'
                ? $this->spareParts->partsForVehicle($prediction['entity_id'], 180)
                : null;

            $recommendation = IntelligenceRecommendation::query()->create([
                'tenant_id' => $tenantId,
                'prediction_id' => $predictionId,
                'entity_type' => $prediction['entity_type'],
                'entity_id' => $prediction['entity_id'],
                'insight_level' => 'PRESCRIPTIVE',
                'recommendation_type' => $rule['type'],
                'priority' => $rule['priority'],
                'description' => $this->describe($rule['type'], $type, $riskLevel),
                'evidence' => $evidence,
                'suggested_parts' => $suggestedParts,
                'suggested_due_at' => $this->suggestedDueAt($rule['priority']),
                'status' => IntelligenceRecommendation::STATUS_NEW,
                'expires_at' => CarbonImmutable::now()->addDays((int) config('intelligence.recommendation.expiry_days', 30)),
            ]);

            $this->maybeAlert($tenantId, $type, $riskLevel, $prediction, $recommendation);
            $created++;
        }

        return $created;
    }

    private function describe(string $recommendationType, string $sourceType, ?string $riskLevel): string
    {
        $label = str_replace('_', ' ', strtolower($recommendationType));

        return ucfirst($label)." recommended based on {$sourceType} = {$riskLevel}.";
    }

    private function suggestedDueAt(string $priority): CarbonImmutable
    {
        return CarbonImmutable::now()->addDays(match ($priority) {
            'URGENT' => 2,
            'HIGH' => 7,
            'NORMAL' => 30,
            default => 60,
        });
    }

    private function maybeAlert(string $tenantId, string $predictionType, ?string $riskLevel, array $prediction, IntelligenceRecommendation $recommendation): void
    {
        $minLevel = config('intelligence.alerts.min_risk_level_for_alert', 'HIGH');
        $order = ['LOW' => 0, 'MEDIUM' => 1, 'HIGH' => 2, 'CRITICAL' => 3];
        if (! isset($order[$riskLevel]) || $order[$riskLevel] < ($order[$minLevel] ?? 2)) {
            return;
        }

        $eventCode = match (true) {
            $predictionType === 'vehicle_failure_risk' && $riskLevel === 'CRITICAL' => 'intelligence.vehicle_critical',
            $predictionType === 'vehicle_failure_risk' => 'intelligence.vehicle_high_risk',
            $predictionType === 'repeat_failure' => 'intelligence.repeat_failure',
            $predictionType === 'anomaly' => 'intelligence.anomaly_detected',
            $predictionType === 'vehicle_rul' || $predictionType === 'tire_rul' => 'intelligence.rul_low',
            default => 'intelligence.component_high_risk',
        };

        $this->notifications->dispatchEvent($eventCode, $tenantId, [
            'entity_type' => $prediction['entity_type'], 'entity_id' => $prediction['entity_id'],
            'risk_level' => $riskLevel, 'recommendation_type' => $recommendation->recommendation_type,
        ], 'intelligence_recommendation', (string) $recommendation->id);
    }
}
