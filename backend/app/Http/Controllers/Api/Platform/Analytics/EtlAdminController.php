<?php

namespace App\Http\Controllers\Api\Platform\Analytics;

use App\Domain\Analytics\Models\EtlRun;
use App\Domain\Analytics\Services\AnalyticsRunService;
use App\Domain\Audit\Services\AuditService;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

/**
 * Phase 6 Section 50 — minimal ETL administration: last/current/failed
 * runs, retry, backfill, processed counts, duration. Deliberately not a
 * generic enterprise ETL orchestration UI (Section 50's own caution).
 * Every mutating action is audited (Section 64).
 */
class EtlAdminController extends Controller
{
    public function __construct(
        private readonly AnalyticsRunService $runner,
        private readonly AuditService $audit,
    ) {}

    public function index(Request $request)
    {
        $query = EtlRun::query()->withoutGlobalScopes();

        foreach (['status', 'job_type', 'tenant_id', 'business_date'] as $filter) {
            if ($value = $request->string($filter)->value()) {
                $query->where($filter, $value);
            }
        }

        $runs = $query->orderByDesc('started_at')->limit($request->integer('limit', 50))->get();

        return $this->ok($runs);
    }

    public function show(string $run)
    {
        $etlRun = EtlRun::query()->withoutGlobalScopes()->find($run);
        abort_if(! $etlRun, 404);

        return $this->ok($etlRun);
    }

    public function run(Request $request)
    {
        $this->audit->log(
            resourceType: 'AnalyticsEtlRun', resourceId: 'manual-'.now()->timestamp, action: 'triggered',
            oldValues: null,
            newValues: $request->only(['date', 'tenant_id', 'dataset']),
            tenantId: $request->input('tenant_id'),
        );

        $this->runner->run(
            dateOverride: $request->input('date'),
            tenantId: $request->input('tenant_id'),
            datasetKey: $request->input('dataset'),
            trigger: 'manual',
            sync: false,
        );

        return $this->message('Analytics ETL dispatched.', 202);
    }

    public function backfill(Request $request)
    {
        $request->validate(['from' => 'required|date', 'to' => 'nullable|date']);

        $this->audit->log(
            resourceType: 'AnalyticsEtlRun', resourceId: 'backfill-'.now()->timestamp, action: 'backfilled',
            oldValues: null,
            newValues: $request->only(['from', 'to', 'tenant_id', 'dataset']),
            tenantId: $request->input('tenant_id'),
        );

        $from = \Carbon\CarbonImmutable::parse($request->input('from'));
        $to = $request->input('to') ? \Carbon\CarbonImmutable::parse($request->input('to')) : $from;

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $this->runner->run(
                dateOverride: $date->format('Y-m-d'),
                tenantId: $request->input('tenant_id'),
                datasetKey: $request->input('dataset'),
                trigger: 'backfill',
                sync: false,
            );
        }

        return $this->message('Backfill dispatched.', 202);
    }

    public function retry(string $run)
    {
        $etlRun = EtlRun::query()->withoutGlobalScopes()->find($run);
        abort_if(! $etlRun, 404);

        $this->audit->log(
            resourceType: 'AnalyticsEtlRun', resourceId: (string) $etlRun->id, action: 'retried',
            oldValues: ['status' => $etlRun->status],
            newValues: ['job_type' => $etlRun->job_type, 'business_date' => $etlRun->business_date],
            tenantId: $etlRun->tenant_id,
        );

        $this->runner->run(
            dateOverride: $etlRun->business_date,
            tenantId: $etlRun->tenant_id,
            datasetKey: $etlRun->job_type,
            trigger: 'retry',
            sync: false,
        );

        return $this->message('Retry dispatched.', 202);
    }
}
