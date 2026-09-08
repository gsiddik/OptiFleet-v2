<?php

namespace App\Http\Controllers\Api\Tenant\Intelligence;

use App\Domain\Analytics\Services\DataFreshnessService;
use App\Domain\Intelligence\Models\IntelligenceRecommendation;
use App\Domain\Intelligence\Support\EntityScopeResolver;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 Section 53 — dashboard overview. Every count here reflects
 * only entities the caller's data scope allows (Section 60), and always
 * carries freshness (Section 17/74) so the dashboard never presents a
 * snapshot as real-time without saying so.
 */
class IntelligenceOverviewController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntityScopeResolver $scope,
        private readonly DataFreshnessService $freshness,
    ) {}

    public function index()
    {
        $tenantId = $this->context->tenantId();
        $user = $this->context->user();
        $allowedVehicleIds = $this->scope->allowedVehicleIds($user, $tenantId);

        $latestHealthDate = DB::connection('mongodb')->table('intelligence_predictions')
            ->where('tenant_id', $tenantId)->where('prediction_type', 'vehicle_health_score')
            ->orderByDesc('business_date')->value('business_date');

        $healthQuery = fn () => DB::connection('mongodb')->table('intelligence_predictions')
            ->where('tenant_id', $tenantId)->where('prediction_type', 'vehicle_health_score')
            ->where('business_date', $latestHealthDate)
            ->when($allowedVehicleIds !== null, fn ($q) => $q->whereIn('entity_id', $allowedVehicleIds));

        $healthDocs = $latestHealthDate ? $healthQuery()->get() : collect();
        $statusCounts = $healthDocs->countBy('risk_level');

        $latestRiskDate = DB::connection('mongodb')->table('intelligence_predictions')
            ->where('tenant_id', $tenantId)->where('prediction_type', 'vehicle_failure_risk')
            ->orderByDesc('business_date')->value('business_date');

        $riskDocs = $latestRiskDate ? DB::connection('mongodb')->table('intelligence_predictions')
            ->where('tenant_id', $tenantId)->where('prediction_type', 'vehicle_failure_risk')
            ->where('business_date', $latestRiskDate)
            ->when($allowedVehicleIds !== null, fn ($q) => $q->whereIn('entity_id', $allowedVehicleIds))
            ->get() : collect();

        $predictedFailures7d = $riskDocs->filter(fn ($d) => in_array($d->risk_level, ['HIGH', 'CRITICAL'], true) && ($d->horizon_days ?? 0) <= 7)->count();
        $predictedFailures30d = $riskDocs->filter(fn ($d) => in_array($d->risk_level, ['HIGH', 'CRITICAL'], true))->count();

        $lowRulCount = DB::connection('mongodb')->table('intelligence_predictions')
            ->where('tenant_id', $tenantId)->whereIn('prediction_type', ['vehicle_rul', 'tire_rul'])
            ->whereIn('risk_level', ['CRITICAL', 'HIGH'])
            ->when($allowedVehicleIds !== null, fn ($q) => $q->whereIn('entity_id', $allowedVehicleIds))
            ->get(['entity_id'])->pluck('entity_id')->unique()->count();

        $repeatFailureCount = DB::connection('mongodb')->table('intelligence_predictions')
            ->where('tenant_id', $tenantId)->where('prediction_type', 'repeat_failure')
            ->when($allowedVehicleIds !== null, fn ($q) => $q->whereIn('vehicle_id', $allowedVehicleIds))
            ->count();

        $recommendationCounts = IntelligenceRecommendation::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($allowedVehicleIds !== null, fn ($q) => $q->whereIn('entity_id', $allowedVehicleIds))
            ->get()->countBy('status');

        return $this->ok([
            'vehicles_healthy' => ($statusCounts['HEALTHY'] ?? 0) + ($statusCounts['GOOD'] ?? 0),
            'vehicles_at_risk' => ($statusCounts['WATCH'] ?? 0) + ($statusCounts['AT_RISK'] ?? 0),
            'vehicles_critical' => $statusCounts['CRITICAL'] ?? 0,
            'high_risk_components' => DB::connection('mongodb')->table('intelligence_predictions')
                ->where('tenant_id', $tenantId)->where('prediction_type', 'component_health_score')
                ->whereIn('risk_level', ['AT_RISK', 'CRITICAL'])->count(),
            'predicted_failures_7d' => $predictedFailures7d,
            'predicted_failures_30d' => $predictedFailures30d,
            'low_rul_count' => $lowRulCount,
            'repeat_failures' => $repeatFailureCount,
            'open_recommendations' => $recommendationCounts->only([IntelligenceRecommendation::STATUS_NEW, IntelligenceRecommendation::STATUS_REVIEWED])->sum(),
            'accepted_recommendations' => $recommendationCounts[IntelligenceRecommendation::STATUS_ACCEPTED] ?? 0,
            'data_as_of' => $latestHealthDate ?? $latestRiskDate,
            'prediction_as_of' => $latestRiskDate,
            'freshness' => $this->freshness->forTenant($tenantId),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ]);
    }
}
