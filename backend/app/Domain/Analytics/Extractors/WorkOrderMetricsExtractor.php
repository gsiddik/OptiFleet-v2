<?php

namespace App\Domain\Analytics\Extractors;

use App\Domain\Analytics\Contracts\DatasetExtractor;
use App\Domain\Analytics\Support\AnalyticsUpsertWriter;
use App\Domain\Analytics\Support\BusinessDateResolver;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Domain\Identity\Models\Tenant;
use App\Domain\WorkOrder\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 Section 21 — daily_work_order_metrics, one document per
 * (tenant, snapshot_date, workshop) plus a tenant-wide rollup.
 *
 * Formulas (all durations in minutes, all boundaries UTC per
 * BusinessDateResolver::utcBoundsForBusinessDate — Section 15):
 *   created                = count(created_at in [start,end))
 *   completed               = count(completed_at in [start,end))
 *   closed                  = count(closed_at in [start,end))
 *   cancelled               = count(status=CANCELLED, updated_at in [start,end))
 *                             — WorkOrder has no dedicated cancelled_at
 *                             column, so updated_at is used as a documented
 *                             proxy for "when the cancellation happened".
 *   open_as_of_date         = count(created_at <= end, not yet completed,
 *                             status not in CANCELLED/REJECTED) — a
 *                             point-in-time balance, not a same-day event.
 *   overdue                 = subset of open_as_of_date where
 *                             target_completion_at < end.
 *   avg_cycle_time_minutes  = avg(completed_at - created_at) over WOs
 *                             completed in [start,end).
 *   avg_repair_time_minutes = avg(completed_at - started_at) over the
 *                             same set (excludes WOs missing started_at).
 *   rework_rate             = (WOs in that completed set whose audit trail
 *                             shows a status transition into REWORK at any
 *                             point) / completed, x100. Uses the existing
 *                             audit_logs trail (Section: reuse audit) since
 *                             WorkOrder itself keeps no status-history
 *                             table.
 *
 * NOT computed (source data does not exist in Phase 1-5, per Section 39
 * "only expose a KPI if its source data exists"): average approval time
 * (no approved_at column) and first-time-fix rate (no linkage identifying
 * a WO as a return visit for the same failure).
 */
class WorkOrderMetricsExtractor implements DatasetExtractor
{
    public function __construct(
        private readonly AnalyticsUpsertWriter $writer,
        private readonly BusinessDateResolver $businessDates,
    ) {}

    public function key(): string
    {
        return 'work_order_metrics';
    }

    public function label(): string
    {
        return 'Work Order Metrics';
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

        $workshopIds = WorkOrder::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->distinct()->pluck('workshop_id');

        $result->sourceCount = WorkOrder::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->count();

        $documents = [];
        $documents[] = $this->buildDocument($tenantId, $snapshotDate, null, $start, $end);
        foreach ($workshopIds as $workshopId) {
            $documents[] = $this->buildDocument($tenantId, $snapshotDate, $workshopId, $start, $end);
        }

        $result->processedCount = count($documents);
        $this->writer->upsertMany('daily_work_order_metrics', $documents, $result);

        return $result;
    }

    private function buildDocument(string $tenantId, string $snapshotDate, ?string $workshopId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $scope = fn () => WorkOrder::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($workshopId, fn ($q) => $q->where('workshop_id', $workshopId));

        $created = (clone $scope())->whereBetween('created_at', [$start, $end])->count();
        $completed = (clone $scope())->whereBetween('completed_at', [$start, $end])->count();
        $closed = (clone $scope())->whereBetween('closed_at', [$start, $end])->count();
        $cancelled = (clone $scope())->where('status', 'CANCELLED')->whereBetween('updated_at', [$start, $end])->count();

        $openAsOf = (clone $scope())
            ->where('created_at', '<=', $end)
            ->whereNull('completed_at')
            ->whereNotIn('status', ['CANCELLED', 'REJECTED'])
            ->get(['id', 'target_completion_at']);

        $overdue = $openAsOf->filter(fn ($wo) => $wo->target_completion_at && $wo->target_completion_at->lt($end))->count();

        $completedRows = (clone $scope())
            ->whereBetween('completed_at', [$start, $end])
            ->get(['id', 'created_at', 'started_at', 'completed_at']);

        $cycleMinutes = $completedRows->map(fn ($wo) => $wo->created_at->diffInMinutes($wo->completed_at));
        $repairMinutes = $completedRows->filter(fn ($wo) => $wo->started_at)->map(fn ($wo) => $wo->started_at->diffInMinutes($wo->completed_at));

        $reworkCount = 0;
        if ($completedRows->isNotEmpty()) {
            $reworkCount = DB::table('audit_logs')
                ->where('resource_type', 'WorkOrder')
                ->where('action', 'updated')
                ->whereIn('resource_id', $completedRows->pluck('id'))
                ->whereRaw("new_values->>'status' = 'REWORK'")
                ->distinct('resource_id')
                ->count('resource_id');
        }

        $doc = [
            'tenant_id' => $tenantId,
            'snapshot_date' => $snapshotDate,
            'workshop_id' => $workshopId,
            'created' => $created,
            'completed' => $completed,
            'closed' => $closed,
            'cancelled' => $cancelled,
            'open_as_of_date' => $openAsOf->count(),
            'overdue' => $overdue,
            'avg_cycle_time_minutes' => $cycleMinutes->isNotEmpty() ? round($cycleMinutes->avg(), 1) : null,
            'avg_repair_time_minutes' => $repairMinutes->isNotEmpty() ? round($repairMinutes->avg(), 1) : null,
            'rework' => [
                'count' => $reworkCount,
                'of_completed' => $completedRows->count(),
                'rate_percentage' => $completedRows->count() > 0 ? round(($reworkCount / $completedRows->count()) * 100, 2) : null,
            ],
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        return [
            'key' => ['tenant_id' => $tenantId, 'snapshot_date' => $snapshotDate, 'workshop_id' => $workshopId],
            'doc' => $doc,
        ];
    }
}
