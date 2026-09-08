<?php

namespace App\Http\Controllers\Api\Tenant\Intelligence;

use App\Domain\Analytics\Services\DataFreshnessService;
use App\Domain\Intelligence\Support\EntityScopeResolver;
use App\Domain\Intelligence\Tire\TireIntelligenceService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;

/** Phase 7 Section 34 — tire RUL/product performance/abnormal wear. */
class TireIntelligenceApiController extends Controller
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EntityScopeResolver $scope,
        private readonly DataFreshnessService $freshness,
        private readonly TireIntelligenceService $tireIntelligence,
    ) {}

    public function index()
    {
        $tenantId = $this->context->tenantId();
        $allowedVehicleIds = $this->scope->allowedVehicleIds($this->context->user(), $tenantId);

        $latestDate = DB::connection('mongodb')->table('intelligence_predictions')
            ->where('tenant_id', $tenantId)->where('prediction_type', 'tire_rul')
            ->orderByDesc('business_date')->value('business_date');

        $tires = $latestDate ? DB::connection('mongodb')->table('intelligence_predictions')
            ->where('tenant_id', $tenantId)->where('prediction_type', 'tire_rul')->where('business_date', $latestDate)
            ->when($allowedVehicleIds !== null, fn ($q) => $q->whereIn('vehicle_id', $allowedVehicleIds))
            ->get() : collect();

        return $this->ok([
            'tires' => $tires->values(),
            'product_performance' => $this->tireIntelligence->productPerformance($tenantId),
            'abnormal_wear' => $this->tireIntelligence->abnormalWearTires($tenantId),
            'business_date' => $latestDate,
            'freshness' => $this->freshness->forTenant($tenantId),
        ]);
    }
}
