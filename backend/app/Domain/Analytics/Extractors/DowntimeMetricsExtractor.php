<?php

namespace App\Domain\Analytics\Extractors;

use App\Domain\Analytics\Contracts\DatasetExtractor;
use App\Domain\Analytics\Support\AnalyticsUpsertWriter;
use App\Domain\Analytics\Support\BusinessDateResolver;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Domain\Breakdown\Models\Breakdown;
use App\Domain\History\Services\DowntimeService;
use App\Domain\Identity\Models\Tenant;
use App\Domain\WorkOrder\Models\WorkOrder;
use Carbon\CarbonImmutable;

/**
 * Phase 6 Section 23 (Downtime) + Section 24 (MTTR) + Section 25 (MTBF
 * foundation) — daily_downtime_metrics, one document per
 * (tenant, snapshot_date, vehicle). No tenant/branch rollup: downtime and
 * reliability are inherently per-vehicle figures; aggregate views are
 * built by the KPI/API layer averaging across these documents, not by a
 * separate rollup write.
 *
 * Timestamp boundaries (Section 23), all from DowntimeService (Phase 3),
 * reused rather than recomputed:
 *   response_time = breakdown "off-road" moment -> Work Order started_at
 *   repair_time   = started_at -> completed_at
 *   qc_time       = completed_at -> first PASS/COMPLETED QC inspection
 *                   (DowntimeService calls this "waiting_time"; Section 23
 *                   asks for "QC time", which is exactly this stage)
 *   total_downtime = vehicle off-road -> vehicle released
 * Averaged here over Work Orders for this vehicle *completed* within
 * [start,end) of the business date.
 *
 * MTTR (Section 24): total qualifying repair duration / number of
 * qualifying repairs, where "qualifying" = status in
 * config('analytics.mttr_qualifying_statuses') (default COMPLETED,
 * CLOSED) with both started_at and completed_at recorded. Rejected/
 * cancelled Work Orders never qualify, matching Section 24 explicitly.
 *
 * MTBF foundation (Section 25): total observed hours (lookback window
 * ending at business date, config('analytics.vehicle_health.
 * lookback_days') reused so both figures share one window) / number of
 * breakdowns reported for this vehicle in that window. Descriptive
 * reliability statistic — not a Phase 7 prediction.
 */
class DowntimeMetricsExtractor implements DatasetExtractor
{
    public function __construct(
        private readonly AnalyticsUpsertWriter $writer,
        private readonly BusinessDateResolver $businessDates,
        private readonly DowntimeService $downtime,
    ) {}

    public function key(): string
    {
        return 'downtime_metrics';
    }

    public function label(): string
    {
        return 'Downtime & Reliability';
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
        $mtbfWindowStart = $end->subDays((int) config('analytics.vehicle_health.lookback_days', 90));
        $qualifyingStatuses = config('analytics.mttr_qualifying_statuses', ['COMPLETED', 'CLOSED']);

        $vehicleIds = WorkOrder::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereBetween('completed_at', [$start, $end])
            ->distinct()->pluck('vehicle_id')
            ->merge(
                Breakdown::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)
                    ->whereBetween('reported_at', [$mtbfWindowStart, $end])->distinct()->pluck('vehicle_id')
            )->unique()->values();

        $result->sourceCount = $vehicleIds->count();

        $documents = [];
        foreach ($vehicleIds as $vehicleId) {
            $documents[] = $this->buildDocument($tenantId, $snapshotDate, $vehicleId, $start, $end, $mtbfWindowStart, $qualifyingStatuses);
        }

        $result->processedCount = count($documents);
        $this->writer->upsertMany('daily_downtime_metrics', $documents, $result);

        return $result;
    }

    private function buildDocument(string $tenantId, string $snapshotDate, string $vehicleId, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $mtbfWindowStart, array $qualifyingStatuses): array
    {
        $completedWos = WorkOrder::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('vehicle_id', $vehicleId)
            ->whereBetween('completed_at', [$start, $end])
            ->get();

        $samples = $completedWos->map(fn (WorkOrder $wo) => $this->downtime->forWorkOrder($wo));
        $avg = fn (string $field) => $samples->pluck($field)->filter(fn ($v) => $v !== null)->avg();

        $qualifying = $completedWos->filter(fn (WorkOrder $wo) => in_array($wo->status, $qualifyingStatuses, true) && $wo->started_at && $wo->completed_at);
        $mttrMinutes = $qualifying->isNotEmpty()
            ? $qualifying->sum(fn (WorkOrder $wo) => $wo->started_at->diffInMinutes($wo->completed_at)) / $qualifying->count()
            : null;

        $breakdownCount = Breakdown::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->where('vehicle_id', $vehicleId)
            ->whereBetween('reported_at', [$mtbfWindowStart, $end])->count();
        $observedHours = $mtbfWindowStart->diffInHours($end);
        $mtbfHours = $breakdownCount > 0 ? round($observedHours / $breakdownCount, 1) : null;

        $doc = [
            'tenant_id' => $tenantId,
            'snapshot_date' => $snapshotDate,
            'vehicle_id' => $vehicleId,
            'avg_response_time_minutes' => $avg('response_time_minutes') ? round($avg('response_time_minutes'), 1) : null,
            'avg_repair_time_minutes' => $avg('repair_time_minutes') ? round($avg('repair_time_minutes'), 1) : null,
            'avg_qc_time_minutes' => $avg('waiting_time_minutes') ? round($avg('waiting_time_minutes'), 1) : null,
            'avg_total_downtime_minutes' => $avg('total_downtime_minutes') ? round($avg('total_downtime_minutes'), 1) : null,
            'mttr_minutes' => $mttrMinutes !== null ? round($mttrMinutes, 1) : null,
            'mttr_sample_size' => $qualifying->count(),
            'mtbf_hours' => $mtbfHours,
            'mtbf_breakdown_count' => $breakdownCount,
            'mtbf_observed_hours' => $observedHours,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        return [
            'key' => ['tenant_id' => $tenantId, 'snapshot_date' => $snapshotDate, 'vehicle_id' => $vehicleId],
            'doc' => $doc,
        ];
    }
}
