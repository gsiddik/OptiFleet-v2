<?php

namespace App\Http\Controllers\Api\Tenant\Intelligence;

use App\Domain\Analytics\Services\DataFreshnessService;
use App\Domain\Intelligence\Models\IntelligenceRecommendation;
use App\Domain\Intelligence\Support\EntityScopeResolver;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 Section 54 — vehicle intelligence list + detail. Section 60
 * data scope: a branch-restricted caller only ever sees vehicles their
 * DataScopeService assignment allows.
 */
class VehicleIntelligenceController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntityScopeResolver $scope,
        private readonly DataFreshnessService $freshness,
    ) {}

    public function index(Request $request)
    {
        $tenantId = $this->context->tenantId();
        $allowedVehicleIds = $this->scope->allowedVehicleIds($this->context->user(), $tenantId);

        $latestDate = DB::connection('mongodb')->table('intelligence_predictions')
            ->where('tenant_id', $tenantId)->where('prediction_type', 'vehicle_health_score')
            ->orderByDesc('business_date')->value('business_date');

        if (! $latestDate) {
            return $this->ok(['vehicles' => [], 'freshness' => $this->freshness->forTenant($tenantId)]);
        }

        $health = DB::connection('mongodb')->table('intelligence_predictions')
            ->where('tenant_id', $tenantId)->where('prediction_type', 'vehicle_health_score')->where('business_date', $latestDate)
            ->when($allowedVehicleIds !== null, fn ($q) => $q->whereIn('entity_id', $allowedVehicleIds))
            ->when($request->filled('status'), fn ($q) => $q->where('risk_level', $request->string('status')->value()))
            ->get();

        $risk = DB::connection('mongodb')->table('intelligence_predictions')
            ->where('tenant_id', $tenantId)->where('prediction_type', 'vehicle_failure_risk')->where('business_date', $latestDate)
            ->get()->keyBy('entity_id');

        $vehicles = $health->map(fn ($h) => [
            'vehicle_id' => $h->entity_id,
            'health_score' => $h->score,
            'health_status' => $h->risk_level,
            'failure_risk_level' => $risk[$h->entity_id]->risk_level ?? null,
            'failure_risk_score' => $risk[$h->entity_id]->score ?? null,
            'confidence' => $h->confidence,
        ])->values();

        return $this->ok([
            'vehicles' => $vehicles,
            'business_date' => $latestDate,
            'freshness' => $this->freshness->forTenant($tenantId),
        ]);
    }

    /** Section 54: health/risk trend, component health, RUL, key factors, recommendations, prediction history. */
    public function show(string $vehicleId)
    {
        $tenantId = $this->context->tenantId();
        abort_unless($this->scope->canAccessVehicle($this->context->user(), $tenantId, $vehicleId), 403, 'This vehicle is outside your assigned data scope.');

        $mongo = DB::connection('mongodb')->table('intelligence_predictions')->where('tenant_id', $tenantId)->where('entity_id', $vehicleId);

        $latestByType = fn (string $type) => (clone $mongo)->where('prediction_type', $type)->orderByDesc('business_date')->first();
        $historyByType = fn (string $type, int $limit = 30) => (clone $mongo)->where('prediction_type', $type)->orderByDesc('business_date')->limit($limit)->get(['business_date', 'score', 'risk_level'])->values();

        $componentHealth = DB::connection('mongodb')->table('intelligence_predictions')
            ->where('tenant_id', $tenantId)->where('prediction_type', 'component_health_score')->where('vehicle_id', $vehicleId)
            ->orderByDesc('business_date')->limit(20)->get();

        $recommendations = IntelligenceRecommendation::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('entity_id', $vehicleId)
            ->orderByDesc('created_at')->limit(20)->get();

        return $this->ok([
            'vehicle_id' => $vehicleId,
            'health' => $latestByType('vehicle_health_score'),
            'health_history' => $historyByType('vehicle_health_score'),
            'failure_risk' => $latestByType('vehicle_failure_risk'),
            'failure_risk_history' => $historyByType('vehicle_failure_risk'),
            'rul' => $latestByType('vehicle_rul'),
            'repeat_failures' => (clone $mongo)->where('prediction_type', 'repeat_failure')->orderByDesc('business_date')->limit(10)->get(),
            'anomalies' => (clone $mongo)->where('prediction_type', 'anomaly')->orderByDesc('business_date')->limit(10)->get(),
            'component_health' => $componentHealth,
            'recommendations' => $recommendations,
            'freshness' => $this->freshness->forTenant($tenantId),
        ]);
    }
}
