<?php

namespace App\Domain\Analytics\Extractors;

use App\Domain\Analytics\Contracts\DatasetExtractor;
use App\Domain\Analytics\Support\AnalyticsUpsertWriter;
use App\Domain\Analytics\Support\BusinessDateResolver;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Domain\Identity\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 Section 28 — daily_mechanic_metrics, one document per
 * (tenant, snapshot_date, mechanic). No rollup document: per-workshop or
 * per-tenant mechanic aggregates are computed by the API layer averaging
 * across these documents.
 *
 * assigned_jobs    = count(work_order_mechanic_assignments.assigned_at in
 *                    [start,end)) for this worker.
 * completed_jobs   = count(maintenance_jobs.assigned_mechanic = worker,
 *                    status=COMPLETED, completed_at in [start,end)).
 * actual_labor_minutes = sum(work_order_labor_logs.actual_minutes) for
 *                    this worker's logs with completed_at in [start,end).
 * avg_job_duration_minutes = actual_labor_minutes / completed_jobs.
 * workload         = count(maintenance_jobs assigned to worker with
 *                    status in ASSIGNED/IN_PROGRESS/ON_HOLD) as of end —
 *                    point-in-time open workload, not a same-day event.
 * utilization_percentage = actual_labor_minutes /
 *                    config('analytics.mechanic_standard_shift_minutes')
 *                    — Phase 1-5 has no shift/roster table, so this is
 *                    measured against a configured standard shift length,
 *                    not the worker's actual scheduled hours (documented
 *                    assumption, Section 28: "without clear metrics/
 *                    context" — the assumption itself is the context).
 *
 * This is descriptive workload/output reporting, not an opaque
 * performance verdict on the individual.
 */
class MechanicMetricsExtractor implements DatasetExtractor
{
    public function __construct(
        private readonly AnalyticsUpsertWriter $writer,
        private readonly BusinessDateResolver $businessDates,
    ) {}

    public function key(): string
    {
        return 'mechanic_metrics';
    }

    public function label(): string
    {
        return 'Mechanic Metrics';
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

        $workerIds = DB::table('workers')->where('tenant_id', $tenantId)->pluck('id');
        $result->sourceCount = $workerIds->count();

        $documents = [];
        foreach ($workerIds as $workerId) {
            $documents[] = $this->buildDocument($tenantId, $snapshotDate, $workerId, $start, $end);
        }

        $result->processedCount = count($documents);
        $this->writer->upsertMany('daily_mechanic_metrics', $documents, $result);

        return $result;
    }

    private function buildDocument(string $tenantId, string $snapshotDate, string $workerId, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $assignedJobs = DB::table('work_order_mechanic_assignments')
            ->where('worker_id', $workerId)
            ->whereBetween('assigned_at', [$start, $end])
            ->count();

        $completedJobs = DB::table('maintenance_jobs')
            ->where('assigned_mechanic', $workerId)->where('status', 'COMPLETED')
            ->whereBetween('completed_at', [$start, $end])
            ->count();

        $actualLaborMinutes = (int) DB::table('work_order_labor_logs')
            ->where('worker_id', $workerId)
            ->whereBetween('completed_at', [$start, $end])
            ->sum('actual_minutes');

        $workload = DB::table('maintenance_jobs')
            ->where('assigned_mechanic', $workerId)
            ->whereIn('status', ['ASSIGNED', 'IN_PROGRESS', 'ON_HOLD'])
            ->count();

        $shiftMinutes = (int) config('analytics.mechanic_standard_shift_minutes', 480);

        $doc = [
            'tenant_id' => $tenantId,
            'snapshot_date' => $snapshotDate,
            'mechanic_id' => $workerId,
            'assigned_jobs' => $assignedJobs,
            'completed_jobs' => $completedJobs,
            'actual_labor_minutes' => $actualLaborMinutes,
            'avg_job_duration_minutes' => $completedJobs > 0 ? round($actualLaborMinutes / $completedJobs, 1) : null,
            'workload' => $workload,
            'utilization' => [
                'actual_minutes' => $actualLaborMinutes,
                'standard_shift_minutes' => $shiftMinutes,
                'percentage' => round(($actualLaborMinutes / $shiftMinutes) * 100, 2),
            ],
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        return [
            'key' => ['tenant_id' => $tenantId, 'snapshot_date' => $snapshotDate, 'mechanic_id' => $workerId],
            'doc' => $doc,
        ];
    }
}
