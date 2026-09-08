<?php

namespace App\Domain\Intelligence\Extractors;

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
 * Phase 7 Section 4-5: per-vehicle daily feature row for the intelligence
 * layer. Every value here is computed strictly from data timestamped at
 * or before $end (the tenant business date's end-of-day boundary in UTC)
 * — this is the release-critical temporal cutoff (Section 67): nothing
 * that happened after $end may influence a feature computed for this
 * feature_date. Label builders (which use data strictly *after* $end)
 * are a separate, later step — never mixed into this class.
 *
 * Telematics-derived signals (Section 6) have no source in Phase 1-6 and
 * are written as explicit nulls with an availability flag rather than
 * fabricated, so a future ingestion pipeline can populate them without
 * a schema change.
 */
class VehicleFeatureExtractor implements DatasetExtractor
{
    public function __construct(
        private readonly AnalyticsUpsertWriter $writer,
        private readonly BusinessDateResolver $businessDates,
        private readonly DowntimeService $downtime,
    ) {}

    public function key(): string
    {
        return 'vehicle_features';
    }

    public function label(): string
    {
        return 'Vehicle Features';
    }

    public function version(): string
    {
        return config('intelligence.feature_set_versions.vehicle', 'v1');
    }

    public function run(string $tenantId, CarbonImmutable $businessDate): EtlDatasetResult
    {
        $result = new EtlDatasetResult;
        $tenant = Tenant::query()->withoutGlobalScopes()->find($tenantId);
        $featureDate = $businessDate->format('Y-m-d');
        [, $end] = $this->businessDates->utcBoundsForBusinessDate($tenant, $featureDate);
        $lookbackDays = (int) config('intelligence.feature_lookback_days', 90);
        $windowStart = $end->subDays($lookbackDays);

        $vehicles = Vehicle::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('status', '!=', 'DISPOSED')
            ->get(['id', 'year', 'current_odometer', 'engine_hour', 'created_at']);

        $result->sourceCount = $vehicles->count();

        $documents = [];
        foreach ($vehicles as $vehicle) {
            $documents[] = $this->buildDocument($tenantId, $featureDate, $vehicle, $windowStart, $end);
        }

        $result->processedCount = count($documents);
        $this->writer->upsertMany('vehicle_daily_features', $documents, $result);

        return $result;
    }

    private function buildDocument(string $tenantId, string $featureDate, Vehicle $vehicle, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $vehicleId = $vehicle->id;

        $ageDays = max(1, $vehicle->created_at ? $vehicle->created_at->diffInDays($end) : 1);
        $currentOdometer = (float) $vehicle->current_odometer;
        $kmPerDay = $ageDays > 0 ? round($currentOdometer / $ageDays, 2) : null;

        $schedules = DB::table('maintenance_schedules')
            ->where('vehicle_id', $vehicleId)
            ->where('created_at', '<=', $end)
            ->get(['status', 'next_due_date', 'next_due_odometer', 'last_completed_at', 'last_completed_odometer']);

        $overdueMaintenanceCount = $schedules->where('status', 'OVERDUE')->count();
        $lastCompletedAt = $schedules->pluck('last_completed_at')->filter()->map(fn ($v) => CarbonImmutable::parse($v))->sort()->last();
        $lastCompletedOdometer = $schedules->pluck('last_completed_odometer')->filter()->max();
        $daysSinceLastMaintenance = $lastCompletedAt ? $lastCompletedAt->diffInDays($end) : null;
        $kmSinceLastMaintenance = $lastCompletedOdometer !== null ? round($currentOdometer - (float) $lastCompletedOdometer, 2) : null;

        $nearestDueOdometerRemaining = $schedules->pluck('next_due_odometer')->filter()->map(fn ($v) => (float) $v - $currentOdometer)->sort()->first();
        $nearestDueDateDaysRemaining = $schedules->pluck('next_due_date')->filter()
            ->map(fn ($v) => $end->diffInDays(CarbonImmutable::parse($v), false))
            ->sort()->first();

        $breakdownCounts = [];
        foreach ([30, 60, 90] as $days) {
            $breakdownCounts[$days] = Breakdown::query()->withoutGlobalScopes()
                ->where('vehicle_id', $vehicleId)
                ->whereBetween('reported_at', [$end->subDays($days), $end])
                ->count();
        }

        $repeatRepairCount = MaintenanceRequest::query()->withoutGlobalScopes()
            ->where('vehicle_id', $vehicleId)->whereNotNull('component_group_id')
            ->whereBetween('created_at', [$start, $end])
            ->select('component_group_id', DB::raw('count(*) as cnt'))
            ->groupBy('component_group_id')->having(DB::raw('count(*)'), '>', 1)->get()
            ->sum(fn ($row) => $row->cnt - 1);

        $criticalFindingCount = DB::table('inspection_findings')
            ->where('vehicle_id', $vehicleId)->where('severity', 'CRITICAL')
            ->whereBetween('created_at', [$start, $end])->count();

        $downtimeMinutes = WorkOrder::query()->withoutGlobalScopes()
            ->where('vehicle_id', $vehicleId)->whereBetween('completed_at', [$start, $end])->get()
            ->sum(fn (WorkOrder $wo) => $this->downtime->forWorkOrder($wo)['total_downtime_minutes'] ?? 0);

        $mttrHours = $this->mttrHours($vehicleId, $start, $end);
        $mtbfDays = $this->mtbfDays($vehicleId, $end);

        $componentReplacements = DB::table('component_removals as cr')
            ->join('component_installations as ci', 'cr.component_installation_id', '=', 'ci.id')
            ->where('ci.vehicle_id', $vehicleId)->where('cr.disposition', 'SCRAP')
            ->whereBetween('cr.removed_at', [$start, $end])->count();

        $tireReplacements = DB::table('tire_removals as tr')
            ->join('tire_installations as ti', 'tr.tire_installation_id', '=', 'ti.id')
            ->where('ti.vehicle_id', $vehicleId)->where('tr.disposition', 'SCRAP')
            ->whereBetween('tr.removed_at', [$start, $end])->count();

        $warrantyClaims = DB::table('warranty_claims')
            ->where('vehicle_id', $vehicleId)
            ->whereBetween('failure_date', [$start->toDateString(), $end->toDateString()])->count();

        $doc = [
            'tenant_id' => $tenantId,
            'feature_date' => $featureDate,
            'vehicle_id' => $vehicleId,
            'feature_set_version' => $this->version(),
            'source_data_as_of' => $end->toIso8601String(),
            'vehicle_age_days' => $ageDays,
            'current_odometer' => $currentOdometer,
            'engine_hour' => (float) $vehicle->engine_hour,
            'km_per_day' => $kmPerDay,
            'overdue_maintenance_count' => $overdueMaintenanceCount,
            'days_since_last_maintenance' => $daysSinceLastMaintenance,
            'km_since_last_maintenance' => $kmSinceLastMaintenance,
            'nearest_due_odometer_remaining' => $nearestDueOdometerRemaining,
            'nearest_due_date_days_remaining' => $nearestDueDateDaysRemaining,
            'breakdown_count_30d' => $breakdownCounts[30],
            'breakdown_count_60d' => $breakdownCounts[60],
            'breakdown_count_90d' => $breakdownCounts[90],
            'repeat_repair_count_90d' => $repeatRepairCount,
            'critical_inspection_finding_count_90d' => $criticalFindingCount,
            'downtime_minutes_90d' => (int) $downtimeMinutes,
            'mttr_hours' => $mttrHours,
            'mtbf_days' => $mtbfDays,
            'component_replacement_count_90d' => $componentReplacements,
            'tire_replacement_count_90d' => $tireReplacements,
            'warranty_claim_count_90d' => $warrantyClaims,
            // Section 6: telematics has no source in Phase 1-6. Explicit
            // nulls + availability flag, never fabricated values.
            'telematics' => [
                'available' => false,
                'speed' => null, 'rpm' => null, 'coolant_temp' => null, 'oil_pressure' => null,
                'battery_voltage' => null, 'fuel_level' => null, 'harsh_braking_count' => null,
                'harsh_acceleration_count' => null, 'dtc_codes' => null,
            ],
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        return [
            'key' => ['tenant_id' => $tenantId, 'feature_date' => $featureDate, 'vehicle_id' => $vehicleId],
            'doc' => $doc,
        ];
    }

    /** MTTR (Section 24): only WorkOrders reaching a qualifying status with a recorded start/completion. */
    private function mttrHours(string $vehicleId, CarbonImmutable $start, CarbonImmutable $end): ?float
    {
        $qualifying = config('analytics.mttr_qualifying_statuses', ['COMPLETED', 'CLOSED']);

        $orders = WorkOrder::query()->withoutGlobalScopes()
            ->where('vehicle_id', $vehicleId)
            ->whereIn('status', $qualifying)
            ->whereNotNull('started_at')->whereNotNull('completed_at')
            ->whereBetween('completed_at', [$start, $end])
            ->get(['started_at', 'completed_at']);

        if ($orders->isEmpty()) {
            return null;
        }

        $totalHours = $orders->sum(fn (WorkOrder $wo) => $wo->started_at->diffInMinutes($wo->completed_at) / 60);

        return round($totalHours / $orders->count(), 2);
    }

    /** MTBF (Section 25): mean gap between consecutive resolved breakdowns, up to $end. Needs >= 2 to be meaningful. */
    private function mtbfDays(string $vehicleId, CarbonImmutable $end): ?float
    {
        $qualifying = config('analytics.mtbf_qualifying_breakdown_statuses', ['RESOLVED']);

        $dates = Breakdown::query()->withoutGlobalScopes()
            ->where('vehicle_id', $vehicleId)
            ->whereIn('status', $qualifying)
            ->where('reported_at', '<=', $end)
            ->orderBy('reported_at')
            ->pluck('reported_at');

        if ($dates->count() < 2) {
            return null;
        }

        $gaps = [];
        for ($i = 1; $i < $dates->count(); $i++) {
            $gaps[] = CarbonImmutable::parse($dates[$i - 1])->diffInDays(CarbonImmutable::parse($dates[$i]));
        }

        return round(array_sum($gaps) / count($gaps), 1);
    }
}
