<?php

namespace App\Http\Controllers\Api\Tenant\Analytics;

use App\Domain\Analytics\Kpi\KpiRegistry;
use App\Domain\Analytics\Services\DataFreshnessService;
use App\Http\Controllers\Controller;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Phase 6 Section 41 — /analytics/overview: a cross-domain landing page,
 * one headline KPI per major domain plus freshness. Always the
 * tenant-wide rollup (dimension value null) — per-branch/workshop detail
 * lives on each domain's own endpoint.
 */
class OverviewAnalyticsController extends Controller
{
    private const HEADLINE_KPIS = [
        'fleet_availability', 'breakdown_rate', 'maintenance_compliance', 'mttr',
        'workshop_utilization', 'mechanic_utilization', 'stockout_rate',
        'vendor_on_time_delivery', 'maintenance_cost_per_vehicle',
    ];

    public function __construct(
        private readonly TenantContext $context,
        private readonly KpiRegistry $kpis,
        private readonly DataFreshnessService $freshness,
    ) {}

    public function index(Request $request)
    {
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        $tenantId = $this->context->tenantId();
        $to = $request->string('to')->value() ? CarbonImmutable::parse($request->string('to')->value()) : CarbonImmutable::now();
        $from = $request->string('from')->value() ? CarbonImmutable::parse($request->string('from')->value()) : $to->subDays(29);

        $kpis = collect(self::HEADLINE_KPIS)
            ->filter(fn ($code) => $this->kpis->has($code))
            ->map(function ($code) use ($tenantId, $from, $to) {
                try {
                    return $this->kpis->get($code)->calculate($tenantId, $from, $to, null);
                } catch (\Throwable) {
                    return null;
                }
            })
            ->filter()
            ->values();

        return $this->ok([
            'kpis' => $kpis,
            'freshness' => $this->freshness->forTenant($tenantId),
            'period' => ['from' => $from->format('Y-m-d'), 'to' => $to->format('Y-m-d')],
        ]);
    }

    public function kpiCatalog()
    {
        return $this->ok(collect($this->kpis->all())->map->toArray()->values());
    }
}
