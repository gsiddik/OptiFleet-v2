<?php

namespace App\Domain\Analytics\Extractors;

use App\Domain\Analytics\Contracts\DatasetExtractor;
use App\Domain\Analytics\Support\AnalyticsUpsertWriter;
use App\Domain\Analytics\Support\BusinessDateResolver;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Domain\Identity\Models\Tenant;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 Section 20 — daily_maintenance_metrics, one document per
 * (tenant, snapshot_date, branch) plus a tenant-wide rollup.
 *
 * Formulas:
 *   scheduled_maintenance_count = count(maintenance_schedules for the
 *     branch's vehicles, any status) as of business date — point-in-time,
 *     like fleet_snapshot (no schedule-history table exists in Phase 1-5).
 *   overdue_maintenance         = subset with status = OVERDUE.
 *   maintenance_compliance_percentage = (scheduled_maintenance_count -
 *     overdue_maintenance) / scheduled_maintenance_count * 100 — the
 *     share of currently-scheduled maintenance that is NOT overdue. This
 *     is a point-in-time compliance proxy, not a historical on-time-rate
 *     (Phase 1-5 does not record whether a *past* completion beat its due
 *     date).
 *   completed_maintenance       = count(maintenance_schedules whose
 *     last_completed_at falls in [start,end)).
 *   preventive/corrective/breakdown_maintenance = count(Work Orders of
 *     that maintenance_type with completed_at in [start,end)).
 *   repeat_maintenance          = count of (vehicle, component_group)
 *     pairs with >1 MaintenanceRequest in the trailing 30 days ending at
 *     business date, counting only the repeat occurrences (total - one
 *     per distinct pair).
 *   average_maintenance_duration_minutes = avg(completed_at - created_at)
 *     over PREVENTIVE+CORRECTIVE Work Orders completed in [start,end).
 */
class MaintenanceMetricsExtractor implements DatasetExtractor
{
    private const REPEAT_LOOKBACK_DAYS = 30;

    public function __construct(
        private readonly AnalyticsUpsertWriter $writer,
        private readonly BusinessDateResolver $businessDates,
    ) {}

    public function key(): string
    {
        return 'maintenance_metrics';
    }

    public function label(): string
    {
        return 'Maintenance Metrics';
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
        $repeatWindowStart = $end->subDays(self::REPEAT_LOOKBACK_DAYS);

        $branchIds = Vehicle::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->distinct()->pluck('branch_id');
        $result->sourceCount = Vehicle::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

        $documents = [];
        $documents[] = $this->buildDocument($tenantId, $snapshotDate, null, $start, $end, $repeatWindowStart);
        foreach ($branchIds as $branchId) {
            $documents[] = $this->buildDocument($tenantId, $snapshotDate, $branchId, $start, $end, $repeatWindowStart);
        }

        $result->processedCount = count($documents);
        $this->writer->upsertMany('daily_maintenance_metrics', $documents, $result);

        return $result;
    }

    private function buildDocument(string $tenantId, string $snapshotDate, ?string $branchId, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $repeatWindowStart): array
    {
        $vehicleIds = Vehicle::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->pluck('id');

        $scheduleCounts = DB::table('maintenance_schedules')
            ->whereIn('vehicle_id', $vehicleIds)
            ->select('status', DB::raw('count(*) as cnt'))
            ->groupBy('status')
            ->pluck('cnt', 'status');

        $scheduledTotal = (int) $scheduleCounts->sum();
        $overdue = (int) ($scheduleCounts['OVERDUE'] ?? 0);
        $compliance = $scheduledTotal > 0 ? round((($scheduledTotal - $overdue) / $scheduledTotal) * 100, 2) : null;

        $completedSchedules = DB::table('maintenance_schedules')
            ->whereIn('vehicle_id', $vehicleIds)
            ->whereBetween('last_completed_at', [$start, $end])
            ->count();

        $woByType = WorkOrder::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereBetween('completed_at', [$start, $end])
            ->select('maintenance_type', DB::raw('count(*) as cnt'))
            ->groupBy('maintenance_type')
            ->pluck('cnt', 'maintenance_type');

        $preventiveCorrectiveDurations = WorkOrder::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereIn('maintenance_type', ['PREVENTIVE', 'CORRECTIVE'])
            ->whereBetween('completed_at', [$start, $end])
            ->get(['created_at', 'completed_at'])
            ->map(fn ($wo) => $wo->created_at->diffInMinutes($wo->completed_at));

        $repeatCount = $this->countRepeatMaintenance($tenantId, $branchId, $repeatWindowStart, $end);

        $doc = [
            'tenant_id' => $tenantId,
            'snapshot_date' => $snapshotDate,
            'branch_id' => $branchId,
            'scheduled_maintenance_count' => $scheduledTotal,
            'overdue_maintenance' => $overdue,
            'maintenance_compliance_percentage' => $compliance,
            'completed_maintenance' => $completedSchedules,
            'preventive_maintenance' => (int) ($woByType['PREVENTIVE'] ?? 0),
            'corrective_maintenance' => (int) ($woByType['CORRECTIVE'] ?? 0),
            'breakdown_maintenance' => (int) ($woByType['BREAKDOWN'] ?? 0),
            'repeat_maintenance' => $repeatCount,
            'average_maintenance_duration_minutes' => $preventiveCorrectiveDurations->isNotEmpty() ? round($preventiveCorrectiveDurations->avg(), 1) : null,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        return [
            'key' => ['tenant_id' => $tenantId, 'snapshot_date' => $snapshotDate, 'branch_id' => $branchId],
            'doc' => $doc,
        ];
    }

    private function countRepeatMaintenance(string $tenantId, ?string $branchId, CarbonImmutable $start, CarbonImmutable $end): int
    {
        $rows = MaintenanceRequest::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->whereNotNull('component_group_id')
            ->whereBetween('created_at', [$start, $end])
            ->select('vehicle_id', 'component_group_id', DB::raw('count(*) as cnt'))
            ->groupBy('vehicle_id', 'component_group_id')
            ->having(DB::raw('count(*)'), '>', 1)
            ->get();

        return (int) $rows->sum(fn ($row) => $row->cnt - 1);
    }
}
