<?php

namespace App\Domain\Analytics\Extractors;

use App\Domain\Analytics\Contracts\DatasetExtractor;
use App\Domain\Analytics\Support\AnalyticsUpsertWriter;
use App\Domain\Analytics\Support\BusinessDateResolver;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\History\Services\DowntimeService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use App\Domain\Vehicle\Models\Vehicle;
use App\Domain\WorkOrder\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 Section 19 — Vehicle Health Foundation. Deterministic,
 * explainable point-deduction scoring. NOT machine learning, NOT a
 * predictive probability — every weight lives in config('analytics.
 * vehicle_health') and every deduction is reported back in
 * contributing_factors so the number is always explainable.
 *
 * score = base_score - sum(factor_count * weight), floored at 0
 *   (downtime_hours is capped at weights.downtime_hours_cap before
 *   deduction, since a single very long repair should not by itself
 *   drive the score to zero).
 * status = highest status_thresholds bucket the score still clears.
 *
 * All factors are counted over the trailing
 * vehicle_health.lookback_days ending at the business date (default 90):
 *   overdue_maintenance: current maintenance_schedules with status
 *     OVERDUE (point-in-time, not window-bound — a schedule is overdue
 *     or not, "in the last 90 days" does not apply).
 *   open_critical_finding: inspection_findings severity=CRITICAL raised
 *     in the window (Phase 1-5 has no finding-resolution flag, so this
 *     is "recent critical findings", documented as a proxy for "open").
 *   recent_breakdown: Breakdown.reported_at in the window.
 *   repeat_repair: (vehicle, component_group) pairs from
 *     MaintenanceRequest with >1 occurrence in the window, counting only
 *     the repeat occurrences.
 *   downtime_hours: sum(DowntimeService total_downtime_minutes)/60 for
 *     Work Orders completed in the window.
 *   component_failure: component_removals with disposition=SCRAP in the
 *     window, for this vehicle's installations.
 *   tire_condition: tire_removals with disposition=SCRAP in the window,
 *     for this vehicle's installations.
 */
class VehicleHealthExtractor implements DatasetExtractor
{
    public function __construct(
        private readonly AnalyticsUpsertWriter $writer,
        private readonly BusinessDateResolver $businessDates,
        private readonly DowntimeService $downtime,
    ) {}

    public function key(): string
    {
        return 'vehicle_health';
    }

    public function label(): string
    {
        return 'Vehicle Health';
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
        $config = config('analytics.vehicle_health');
        [, $end] = $this->businessDates->utcBoundsForBusinessDate($tenant, $snapshotDate);
        $windowStart = $end->subDays($config['lookback_days']);

        $vehicles = Vehicle::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', 'DISPOSED')
            ->get(['id']);

        $result->sourceCount = $vehicles->count();

        $documents = [];
        foreach ($vehicles as $vehicle) {
            $documents[] = $this->buildDocument($tenantId, $snapshotDate, $vehicle->id, $windowStart, $end, $config);
        }

        $result->processedCount = count($documents);
        $this->writer->upsertMany('daily_vehicle_health', $documents, $result);

        return $result;
    }

    private function buildDocument(string $tenantId, string $snapshotDate, string $vehicleId, CarbonImmutable $start, CarbonImmutable $end, array $config): array
    {
        $weights = $config['weights'];

        $overdueMaintenance = DB::table('maintenance_schedules')->where('vehicle_id', $vehicleId)->where('status', 'OVERDUE')->count();

        $openCriticalFindings = DB::table('inspection_findings')
            ->where('vehicle_id', $vehicleId)->where('severity', 'CRITICAL')
            ->whereBetween('created_at', [$start, $end])->count();

        $recentBreakdowns = Breakdown::query()->withoutGlobalScopes()
            ->where('vehicle_id', $vehicleId)->whereBetween('reported_at', [$start, $end])->count();

        $repeatRepair = MaintenanceRequest::query()->withoutGlobalScopes()
            ->where('vehicle_id', $vehicleId)->whereNotNull('component_group_id')
            ->whereBetween('created_at', [$start, $end])
            ->select('component_group_id', DB::raw('count(*) as cnt'))
            ->groupBy('component_group_id')->having(DB::raw('count(*)'), '>', 1)->get()
            ->sum(fn ($row) => $row->cnt - 1);

        $downtimeMinutes = WorkOrder::query()->withoutGlobalScopes()
            ->where('vehicle_id', $vehicleId)->whereBetween('completed_at', [$start, $end])->get()
            ->sum(fn (WorkOrder $wo) => $this->downtime->forWorkOrder($wo)['total_downtime_minutes'] ?? 0);
        $downtimeHours = round($downtimeMinutes / 60, 1);

        $componentFailures = DB::table('component_removals as cr')
            ->join('component_installations as ci', 'cr.component_installation_id', '=', 'ci.id')
            ->where('ci.vehicle_id', $vehicleId)->where('cr.disposition', 'SCRAP')
            ->whereBetween('cr.removed_at', [$start, $end])->count();

        $tireFailures = DB::table('tire_removals as tr')
            ->join('tire_installations as ti', 'tr.tire_installation_id', '=', 'ti.id')
            ->where('ti.vehicle_id', $vehicleId)->where('tr.disposition', 'SCRAP')
            ->whereBetween('tr.removed_at', [$start, $end])->count();

        $cappedDowntimeHours = min($downtimeHours, $weights['downtime_hours_cap'] / max($weights['downtime_hours'], 0.0001));

        $factors = [
            ['factor' => 'overdue_maintenance', 'count' => $overdueMaintenance, 'points_deducted' => $overdueMaintenance * $weights['overdue_maintenance']],
            ['factor' => 'open_critical_finding', 'count' => $openCriticalFindings, 'points_deducted' => $openCriticalFindings * $weights['open_critical_finding']],
            ['factor' => 'recent_breakdown', 'count' => $recentBreakdowns, 'points_deducted' => $recentBreakdowns * $weights['recent_breakdown']],
            ['factor' => 'repeat_repair', 'count' => $repeatRepair, 'points_deducted' => $repeatRepair * $weights['repeat_repair']],
            ['factor' => 'downtime_hours', 'count' => $downtimeHours, 'points_deducted' => round($cappedDowntimeHours * $weights['downtime_hours'], 1)],
            ['factor' => 'component_failure', 'count' => $componentFailures, 'points_deducted' => $componentFailures * $weights['component_failure']],
            ['factor' => 'tire_condition', 'count' => $tireFailures, 'points_deducted' => $tireFailures * $weights['tire_condition']],
        ];

        $totalDeducted = array_sum(array_column($factors, 'points_deducted'));
        $score = max(0, $config['base_score'] - $totalDeducted);
        $status = $this->statusFor($score, $config['status_thresholds']);

        $doc = [
            'tenant_id' => $tenantId,
            'snapshot_date' => $snapshotDate,
            'vehicle_id' => $vehicleId,
            'vehicle_health_score' => round($score, 1),
            'health_status' => $status,
            'contributing_factors' => $factors,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        return [
            'key' => ['tenant_id' => $tenantId, 'snapshot_date' => $snapshotDate, 'vehicle_id' => $vehicleId],
            'doc' => $doc,
        ];
    }

    private function statusFor(float $score, array $thresholds): string
    {
        arsort($thresholds);
        foreach ($thresholds as $status => $threshold) {
            if ($score >= $threshold) {
                return $status;
            }
        }

        return array_key_last($thresholds);
    }
}
