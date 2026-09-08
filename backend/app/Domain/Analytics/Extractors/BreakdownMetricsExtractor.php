<?php

namespace App\Domain\Analytics\Extractors;

use App\Domain\Analytics\Support\AnalyticsUpsertWriter;
use App\Domain\Analytics\Support\BusinessDateResolver;
use App\Domain\Analytics\Contracts\DatasetExtractor;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\History\Services\DowntimeService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\WorkOrder\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 Section 22 — daily_breakdown_metrics, one document per
 * (tenant, snapshot_date, branch) plus a tenant-wide rollup.
 *
 * breakdown_count / by_severity / immobilized_events: count(reported_at
 *   in [start,end)).
 * top_vehicles / top_component_groups: bounded (max 10, Section 54 — no
 *   unbounded arrays) breakdown counts within the window; full drill-down
 *   goes back to PostgreSQL via the Breakdown API, authorized normally
 *   (Section 46).
 * recurring_breakdown: vehicles with >1 breakdown in the trailing 30 days
 *   ending at business date (same window convention as
 *   MaintenanceMetricsExtractor's repeat_maintenance).
 * response_time / repair_time / total_downtime (avg minutes): reuses
 *   DowntimeService (Phase 3) over breakdowns resolved in [start,end)
 *   that produced a Work Order — the same timestamps this phase was
 *   built to make reusable.
 */
class BreakdownMetricsExtractor implements DatasetExtractor
{
    private const RECURRING_LOOKBACK_DAYS = 30;

    private const TOP_N = 10;

    public function __construct(
        private readonly AnalyticsUpsertWriter $writer,
        private readonly BusinessDateResolver $businessDates,
        private readonly DowntimeService $downtime,
    ) {}

    public function key(): string
    {
        return 'breakdown_metrics';
    }

    public function label(): string
    {
        return 'Breakdown Metrics';
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
        $recurringStart = $end->subDays(self::RECURRING_LOOKBACK_DAYS);

        $branchIds = Breakdown::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->distinct()->pluck('branch_id');
        $result->sourceCount = Breakdown::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->whereBetween('reported_at', [$start, $end])->count();

        $documents = [];
        $documents[] = $this->buildDocument($tenantId, $snapshotDate, null, $start, $end, $recurringStart);
        foreach ($branchIds as $branchId) {
            $documents[] = $this->buildDocument($tenantId, $snapshotDate, $branchId, $start, $end, $recurringStart);
        }

        $result->processedCount = count($documents);
        $this->writer->upsertMany('daily_breakdown_metrics', $documents, $result);

        return $result;
    }

    private function buildDocument(string $tenantId, string $snapshotDate, ?string $branchId, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $recurringStart): array
    {
        $scope = fn () => Breakdown::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId));

        $windowBreakdowns = (clone $scope())->whereBetween('reported_at', [$start, $end])->get();

        $bySeverity = $windowBreakdowns->countBy('severity');

        $topVehicles = $windowBreakdowns->countBy('vehicle_id')
            ->sortDesc()->take(self::TOP_N)
            ->map(fn ($count, $vehicleId) => ['vehicle_id' => $vehicleId, 'count' => $count])
            ->values()->all();

        $componentGroupByRequest = MaintenanceRequest::query()->withoutGlobalScopes()
            ->whereIn('id', $windowBreakdowns->pluck('maintenance_request_id')->filter())
            ->pluck('component_group_id', 'id');

        $topComponentGroups = $windowBreakdowns->pluck('maintenance_request_id')->filter()
            ->map(fn ($mrId) => $componentGroupByRequest[$mrId] ?? null)->filter()
            ->countBy()->sortDesc()->take(self::TOP_N)
            ->map(fn ($count, $groupId) => ['component_group_id' => $groupId, 'count' => $count])
            ->values()->all();

        $recurringCount = (clone $scope())
            ->whereBetween('reported_at', [$recurringStart, $end])
            ->select('vehicle_id', DB::raw('count(*) as cnt'))
            ->groupBy('vehicle_id')
            ->having(DB::raw('count(*)'), '>', 1)
            ->count();

        $resolvedWorkOrderIds = (clone $scope())
            ->whereBetween('resolved_at', [$start, $end])
            ->whereNotNull('work_order_id')
            ->pluck('work_order_id');

        $downtimeSamples = WorkOrder::query()->withoutGlobalScopes()->whereIn('id', $resolvedWorkOrderIds)->get()
            ->map(fn (WorkOrder $wo) => $this->downtime->forWorkOrder($wo));

        $avg = fn (string $field) => $downtimeSamples->pluck($field)->filter(fn ($v) => $v !== null)->avg();

        $doc = [
            'tenant_id' => $tenantId,
            'snapshot_date' => $snapshotDate,
            'branch_id' => $branchId,
            'breakdown_count' => $windowBreakdowns->count(),
            'by_severity' => [
                'minor' => (int) ($bySeverity['MINOR'] ?? 0),
                'major' => (int) ($bySeverity['MAJOR'] ?? 0),
                'immobilized' => (int) ($bySeverity['IMMOBILIZED'] ?? 0),
            ],
            'immobilized_events' => (int) ($bySeverity['IMMOBILIZED'] ?? 0),
            'top_vehicles' => $topVehicles,
            'top_component_groups' => $topComponentGroups,
            'recurring_breakdown_vehicles' => $recurringCount,
            'avg_response_time_minutes' => $avg('response_time_minutes') ? round($avg('response_time_minutes'), 1) : null,
            'avg_repair_time_minutes' => $avg('repair_time_minutes') ? round($avg('repair_time_minutes'), 1) : null,
            'avg_total_downtime_minutes' => $avg('total_downtime_minutes') ? round($avg('total_downtime_minutes'), 1) : null,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        return [
            'key' => ['tenant_id' => $tenantId, 'snapshot_date' => $snapshotDate, 'branch_id' => $branchId],
            'doc' => $doc,
        ];
    }
}
