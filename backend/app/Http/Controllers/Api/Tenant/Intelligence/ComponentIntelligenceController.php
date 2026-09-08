<?php

namespace App\Http\Controllers\Api\Tenant\Intelligence;

use App\Domain\Analytics\Services\DataFreshnessService;
use App\Domain\Intelligence\Support\EntityScopeResolver;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/** Phase 7 Section 55 — component health/failure list, vehicle-scope enforced (Section 60). */
class ComponentIntelligenceController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntityScopeResolver $scope,
        private readonly DataFreshnessService $freshness,
    ) {}

    public function index()
    {
        $tenantId = $this->context->tenantId();
        $allowedVehicleIds = $this->scope->allowedVehicleIds($this->context->user(), $tenantId);

        $latestDate = DB::connection('mongodb')->table('intelligence_predictions')
            ->where('tenant_id', $tenantId)->where('prediction_type', 'component_health_score')
            ->orderByDesc('business_date')->value('business_date');

        $components = $latestDate ? DB::connection('mongodb')->table('intelligence_predictions')
            ->where('tenant_id', $tenantId)->where('prediction_type', 'component_health_score')->where('business_date', $latestDate)
            ->when($allowedVehicleIds !== null, fn ($q) => $q->whereIn('vehicle_id', $allowedVehicleIds))
            ->get() : collect();

        return $this->ok(['components' => $components->values(), 'business_date' => $latestDate, 'freshness' => $this->freshness->forTenant($tenantId)]);
    }
}
