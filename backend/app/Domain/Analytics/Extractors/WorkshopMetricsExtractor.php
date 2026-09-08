<?php

namespace App\Domain\Analytics\Extractors;

use App\Domain\Analytics\Contracts\DatasetExtractor;
use App\Domain\Analytics\Support\AnalyticsUpsertWriter;
use App\Domain\Analytics\Support\BusinessDateResolver;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Models\Workshop;
use App\Domain\WorkOrder\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 Section 26-27 — daily_workshop_metrics, one document per
 * (tenant, snapshot_date, workshop) plus a tenant-wide rollup.
 *
 * wo_throughput / completed_wo = count(completed_at in [start,end)).
 * open_wo    = count(created_at <= end, completed_at null, status not in
 *              CANCELLED/REJECTED) — point-in-time balance.
 * overdue_wo = subset of open_wo where target_completion_at < end.
 * avg_cycle_time_minutes    = avg(created_at -> completed_at).
 * avg_queue_time_minutes    = avg(created_at -> started_at) — time
 *              waiting before work actually began, over WOs completed in
 *              the window (excludes WOs never started).
 *
 * Workspace utilization (Section 27):
 *   occupied_minutes  = sum of each workspace_reservations interval
 *     (status ACTIVE or COMPLETED) clipped to [start,end) of the
 *     business date.
 *   available_minutes = 1440 (a full day) per workspace whose *current*
 *     status is not BLOCKED/UNDER_MAINTENANCE/INACTIVE, else 0 — Phase
 *     1-5 has no workspace-status-history table, so (like the fleet
 *     snapshot) this is a documented point-in-time proxy, not a true
 *     historical reconstruction. Blocked/inactive time is excluded from
 *     capacity per Section 27's explicit instruction.
 *   percentage = occupied_minutes / available_minutes * 100.
 */
class WorkshopMetricsExtractor implements DatasetExtractor
{
    public function __construct(
        private readonly AnalyticsUpsertWriter $writer,
        private readonly BusinessDateResolver $businessDates,
    ) {}

    public function key(): string
    {
        return 'workshop_metrics';
    }

    public function label(): string
    {
        return 'Workshop Metrics';
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

        $workshopIds = Workshop::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('id');
        $result->sourceCount = $workshopIds->count();

        $documents = [];
        $documents[] = $this->buildDocument($tenantId, $snapshotDate, null, $start, $end);
        foreach ($workshopIds as $workshopId) {
            $documents[] = $this->buildDocument($tenantId, $snapshotDate, $workshopId, $start, $end);
        }

        $result->processedCount = count($documents);
        $this->writer->upsertMany('daily_workshop_metrics', $documents, $result);

        return $result;
    }

    private function buildDocument(string $tenantId, string $snapshotDate, ?string $workshopId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $scope = fn () => WorkOrder::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($workshopId, fn ($q) => $q->where('workshop_id', $workshopId));

        $completedWos = (clone $scope())->whereBetween('completed_at', [$start, $end])->get(['id', 'created_at', 'started_at', 'completed_at']);
        $cycleMinutes = $completedWos->map(fn ($wo) => $wo->created_at->diffInMinutes($wo->completed_at));
        $queueMinutes = $completedWos->filter(fn ($wo) => $wo->started_at)->map(fn ($wo) => $wo->created_at->diffInMinutes($wo->started_at));

        $openWos = (clone $scope())
            ->where('created_at', '<=', $end)->whereNull('completed_at')
            ->whereNotIn('status', ['CANCELLED', 'REJECTED'])
            ->get(['target_completion_at']);
        $overdue = $openWos->filter(fn ($wo) => $wo->target_completion_at && $wo->target_completion_at->lt($end))->count();

        [$occupiedMinutes, $availableMinutes] = $this->workspaceUtilization($tenantId, $workshopId, $start, $end);

        $doc = [
            'tenant_id' => $tenantId,
            'snapshot_date' => $snapshotDate,
            'workshop_id' => $workshopId,
            'wo_throughput' => $completedWos->count(),
            'completed_wo' => $completedWos->count(),
            'open_wo' => $openWos->count(),
            'overdue_wo' => $overdue,
            'avg_cycle_time_minutes' => $cycleMinutes->isNotEmpty() ? round($cycleMinutes->avg(), 1) : null,
            'avg_queue_time_minutes' => $queueMinutes->isNotEmpty() ? round($queueMinutes->avg(), 1) : null,
            'workspace_utilization' => [
                'occupied_minutes' => $occupiedMinutes,
                'available_minutes' => $availableMinutes,
                'percentage' => $availableMinutes > 0 ? round(($occupiedMinutes / $availableMinutes) * 100, 2) : null,
            ],
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        return [
            'key' => ['tenant_id' => $tenantId, 'snapshot_date' => $snapshotDate, 'workshop_id' => $workshopId],
            'doc' => $doc,
        ];
    }

    private function workspaceUtilization(string $tenantId, ?string $workshopId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $workspaces = DB::table('workspaces')
            ->where('tenant_id', $tenantId)
            ->when($workshopId, fn ($q) => $q->where('workshop_id', $workshopId))
            ->whereNull('deleted_at')
            ->get(['id', 'status']);

        $availableMinutes = $workspaces->filter(fn ($w) => ! in_array($w->status, ['BLOCKED', 'UNDER_MAINTENANCE', 'INACTIVE'], true))->count() * 1440;

        if ($workspaces->isEmpty()) {
            return [0, 0];
        }

        $reservations = DB::table('workspace_reservations')
            ->whereIn('workspace_id', $workspaces->pluck('id'))
            ->whereIn('status', ['ACTIVE', 'COMPLETED'])
            ->where('start_at', '<', $end)
            ->where('end_at', '>', $start)
            ->get(['start_at', 'end_at']);

        $occupiedMinutes = $reservations->sum(function ($r) use ($start, $end) {
            $clippedStart = CarbonImmutable::parse($r->start_at)->max($start);
            $clippedEnd = CarbonImmutable::parse($r->end_at)->min($end);

            return max(0, $clippedStart->diffInMinutes($clippedEnd));
        });

        return [(int) $occupiedMinutes, (int) $availableMinutes];
    }
}
