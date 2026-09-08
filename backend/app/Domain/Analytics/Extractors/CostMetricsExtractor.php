<?php

namespace App\Domain\Analytics\Extractors;

use App\Domain\Analytics\Contracts\DatasetExtractor;
use App\Domain\Analytics\Support\AnalyticsUpsertWriter;
use App\Domain\Analytics\Support\AnalyticsValidator;
use App\Domain\Analytics\Support\BusinessDateResolver;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Vehicle\Models\Vehicle;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 Section 33-34 — daily_cost_metrics, one document per
 * (tenant, snapshot_date, branch) plus a tenant-wide rollup. All figures
 * come from cost *snapshots already recorded at transaction time*
 * (Section 30/33: never recompute historical cost from current prices):
 *
 * parts_cost      = sum(work_order_planned_parts.total_cost) for Work
 *   Orders of this branch completed in [start,end) — total_cost is the
 *   cost snapshot frozen at issue time (Phase 4).
 * tire_cost       = sum(tires.purchase_cost) for tires installed in the
 *   window on this branch's vehicles + sum(tire_retreads.cost) for
 *   retreads sent in the window for this branch's tires.
 * component_replacement_cost = sum(component_assets.purchase_cost) for
 *   components installed in the window + sum(component_repairs.cost) for
 *   repairs completed in the window, this branch's vehicles.
 * total_maintenance_cost = parts_cost + tire_cost +
 *   component_replacement_cost (+ labor_cost/external_service_cost when
 *   available — see below).
 *
 * NOT computed (Section 39/33 "only expose if source data exists"):
 * labor_cost and external_service_cost are null — Phase 1-5 records
 * labor *time* (work_order_labor_logs.actual_minutes) but no per-worker
 * hourly rate, and no external-service invoice-amount field distinct
 * from parts procurement, so neither can be derived without fabricating
 * a rate.
 *
 * Cost per vehicle / per km / per operating hour (Section 34): computed
 * against the branch's *current* odometer/engine-hour totals (Phase 1-5
 * has no historical odometer-per-day series), guarded against a zero or
 * missing denominator (Section 34's explicit requirement) rather than
 * silently producing an infinite or misleading ratio.
 */
class CostMetricsExtractor implements DatasetExtractor
{
    public function __construct(
        private readonly AnalyticsUpsertWriter $writer,
        private readonly BusinessDateResolver $businessDates,
    ) {}

    public function key(): string
    {
        return 'cost_metrics';
    }

    public function label(): string
    {
        return 'Maintenance Cost Metrics';
    }

    public function version(): string
    {
        return 'v1';
    }

    public function run(string $tenantId, CarbonImmutable $businessDate): EtlDatasetResult
    {
        $result = new EtlDatasetResult;
        $tenant = Tenant::query()->withoutGlobalScopes()->find($tenantId);
        $snapshotDate = $businessDate->format('Y-m-d');
        [$start, $end] = $this->businessDates->utcBoundsForBusinessDate($tenant, $snapshotDate);

        $branchIds = Vehicle::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->distinct()->pluck('branch_id');
        $result->sourceCount = $branchIds->count();

        $documents = [];
        $documents[] = $this->buildDocument($tenantId, $snapshotDate, null, $branchIds->all(), $start, $end);
        foreach ($branchIds as $branchId) {
            $documents[] = $this->buildDocument($tenantId, $snapshotDate, $branchId, [$branchId], $start, $end);
        }

        $result->processedCount = count($documents);
        $this->writer->upsertMany('daily_cost_metrics', $documents, $result);

        return $result;
    }

    private function buildDocument(string $tenantId, string $snapshotDate, ?string $branchId, array $branchIds, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $vehicleIds = Vehicle::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->whereIn('branch_id', $branchIds)->pluck('id');

        $partsCost = (float) DB::table('work_order_planned_parts as wop')
            ->join('work_orders as wo', 'wop.work_order_id', '=', 'wo.id')
            ->whereIn('wo.vehicle_id', $vehicleIds)
            ->whereBetween('wo.completed_at', [$start, $end])
            ->sum('wop.total_cost');

        $tireInstallCost = (float) DB::table('tire_installations as ti')
            ->join('tires as t', 'ti.tire_id', '=', 't.id')
            ->whereIn('ti.vehicle_id', $vehicleIds)
            ->whereBetween('ti.installed_at', [$start, $end])
            ->sum('t.purchase_cost');

        $tireRetreadCost = (float) DB::table('tire_retreads as tr')
            ->join('tires as t', 'tr.tire_id', '=', 't.id')
            ->whereIn('t.current_vehicle_id', $vehicleIds)
            ->whereBetween('tr.sent_at', [$start, $end])
            ->sum('tr.cost');

        $componentInstallCost = (float) DB::table('component_installations as ci')
            ->join('component_assets as ca', 'ci.component_asset_id', '=', 'ca.id')
            ->whereIn('ci.vehicle_id', $vehicleIds)
            ->whereBetween('ci.installed_at', [$start, $end])
            ->sum('ca.purchase_cost');

        $componentRepairCost = (float) DB::table('component_repairs as cr')
            ->join('component_assets as ca', 'cr.component_asset_id', '=', 'ca.id')
            ->whereIn('ca.current_vehicle_id', $vehicleIds)
            ->whereBetween('cr.completed_at', [$start, $end])
            ->sum('cr.cost');

        $tireCost = $tireInstallCost + $tireRetreadCost;
        $componentCost = $componentInstallCost + $componentRepairCost;
        $total = $partsCost + $tireCost + $componentCost;

        $vehicleTotals = Vehicle::query()->withoutGlobalScopes()->whereIn('id', $vehicleIds)
            ->selectRaw('count(*) as vehicle_count, COALESCE(sum(current_odometer),0) as total_odometer, COALESCE(sum(engine_hour),0) as total_engine_hours')
            ->first();

        $doc = [
            'tenant_id' => $tenantId,
            'snapshot_date' => $snapshotDate,
            'branch_id' => $branchId,
            'parts_cost' => round($partsCost, 4),
            'labor_cost' => null,
            'external_service_cost' => null,
            'tire_cost' => round($tireCost, 4),
            'component_replacement_cost' => round($componentCost, 4),
            'total_maintenance_cost' => round($total, 4),
            'cost_per_vehicle' => AnalyticsValidator::safeDivide($total, (float) $vehicleTotals->vehicle_count),
            'cost_per_km' => AnalyticsValidator::safeDivide($total, (float) $vehicleTotals->total_odometer),
            'cost_per_operating_hour' => AnalyticsValidator::safeDivide($total, (float) $vehicleTotals->total_engine_hours),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        return [
            'key' => ['tenant_id' => $tenantId, 'snapshot_date' => $snapshotDate, 'branch_id' => $branchId],
            'doc' => $doc,
        ];
    }
}
