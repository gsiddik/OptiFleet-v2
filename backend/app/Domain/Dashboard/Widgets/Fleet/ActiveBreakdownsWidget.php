<?php

namespace App\Domain\Dashboard\Widgets\Fleet;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * FL-03 Active Breakdowns — breakdowns not yet resolved and how long each has been running
 * (now − COALESCE(downtime_start_at, reported_at)). A running duration, deliberately not MTTR.
 */
class ActiveBreakdownsWidget extends Widget
{
    public function id(): string
    {
        return 'FL-03';
    }

    public function unit(): string
    {
        return 'mixed';
    }

    public function modules(): array
    {
        return ['MAINTENANCE'];
    }

    public function permissions(): array
    {
        return ['breakdown.view'];
    }

    public function compute(DashboardContext $context): array
    {
        $base = $this->open($context);
        $count = (clone $base)->count();
        $bySeverity = self::countsByKey(clone $base, 'bd.severity', ['MINOR', 'MAJOR', 'IMMOBILIZED']);
        $top = $this->rows($context, (clone $base))->limit(5)->get()->map(fn ($r) => $this->present($r, $context))->all();

        return ['data' => ['count' => $count, 'by_severity' => $bySeverity, 'longest' => $top]];
    }

    public function detailRules(): ?array
    {
        return ['severity' => ['nullable', 'in:MINOR,MAJOR,IMMOBILIZED']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $query = $this->rows($context, $this->open($context))
            ->when($params['severity'] ?? null, fn ($q, $s) => $q->where('bd.severity', $s));

        return $this->paginate($query, $params, fn ($r) => $this->present($r, $context));
    }

    private function open(DashboardContext $context): Builder
    {
        $query = DB::table('breakdowns as bd')->where('bd.tenant_id', $context->tenantId)
            ->whereNull('bd.resolved_at')->where('bd.status', '!=', 'RESOLVED');

        return $context->scopeBranch($query, 'bd.branch_id');
    }

    private function rows(DashboardContext $context, Builder $query): Builder
    {
        return $query->leftJoin('vehicles as v', 'v.id', '=', 'bd.vehicle_id')
            ->leftJoin('branches as b', 'b.id', '=', 'bd.branch_id')
            ->leftJoin('work_orders as wo', 'wo.id', '=', 'bd.work_order_id')
            ->orderByRaw('COALESCE(bd.downtime_start_at, bd.reported_at) asc')
            ->select(['bd.id', 'bd.severity', 'bd.status', 'bd.reported_at', 'bd.downtime_start_at', 'v.id as vehicle_id',
                'v.registration_number', 'b.name as branch_name', 'wo.id as work_order_id', 'wo.wo_number']);
    }

    private function present(object $r, DashboardContext $context): array
    {
        $start = $r->downtime_start_at ?? $r->reported_at;
        $hours = $start === null ? null : max(0, intdiv($context->now->getTimestamp() - strtotime($start.' UTC'), 3600));

        return [
            'id' => $r->id, 'severity' => $r->severity, 'status' => $r->status,
            'vehicle_id' => $r->vehicle_id, 'registration_number' => $r->registration_number, 'branch_name' => $r->branch_name,
            'reported_at' => self::isoUtc($r->reported_at),
            'downtime_start_at' => self::isoUtc($r->downtime_start_at),
            'running_hours' => $hours,
            'work_order_id' => $r->work_order_id, 'wo_number' => $r->wo_number,
        ];
    }
}
