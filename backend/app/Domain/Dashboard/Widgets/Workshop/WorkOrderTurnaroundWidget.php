<?php

namespace App\Domain\Dashboard\Widgets\Workshop;

use App\Domain\Dashboard\DashboardContext;

/**
 * WS-04 Work Order Turnaround (not MTTR) — median hours from started_at to completed_at of the Work
 * Orders completed in each month. Pauses are not subtracted (no reliable pause history at WO level);
 * Work Orders without a start time are counted as "not measurable".
 */
class WorkOrderTurnaroundWidget extends CompletedWorkOrdersWidget
{
    public function id(): string
    {
        return 'WS-04';
    }

    public function unit(): string
    {
        return 'mixed';
    }

    public function compute(DashboardContext $context): array
    {
        $rows = $this->completed($context)->whereNotNull('wo.started_at')->whereColumn('wo.completed_at', '>=', 'wo.started_at')
            ->groupBy('month')
            ->selectRaw($context->localMonthSql('wo.completed_at').' as month, count(*) as c,
                percentile_cont(0.5) within group (order by extract(epoch from (wo.completed_at - wo.started_at)) / 3600) as median_hours')
            ->get()->keyBy('month');
        $notMeasurable = $this->completed($context)->where(fn ($q) => $q->whereNull('wo.started_at')->orWhereColumn('wo.completed_at', '<', 'wo.started_at'))->count();

        $months = [];
        foreach ($context->months() as $month) {
            $row = $rows[$month] ?? null;
            $months[] = ['month' => $month, 'is_current' => $month === $context->currentMonth(), 'work_orders' => (int) ($row->c ?? 0),
                'median_hours' => $row ? round((float) $row->median_hours, 1) : null];
        }
        $overall = $this->completed($context)->whereNotNull('wo.started_at')->whereColumn('wo.completed_at', '>=', 'wo.started_at')
            ->selectRaw('count(*) as c, percentile_cont(0.5) within group (order by extract(epoch from (wo.completed_at - wo.started_at)) / 3600) as median_hours')
            ->first();

        return [
            'data' => ['months' => $months, 'median_hours' => $overall->c ? round((float) $overall->median_hours, 1) : null,
                'work_orders' => (int) $overall->c, 'not_measurable' => $notMeasurable],
            'limitations' => $notMeasurable > 0 ? [['code' => 'dashboard.limitations.turnaroundWithoutStart', 'params' => ['count' => $notMeasurable]]] : [],
        ];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $query = $this->withListColumns($this->completed($context, $params['month'] ?? null))
            ->when($params['maintenance_type'] ?? null, fn ($q, $t) => $q->where('wo.maintenance_type', $t))
            ->whereNotNull('wo.started_at')
            ->orderByRaw('(wo.completed_at - wo.started_at) desc');

        return $this->paginate($query, $params, fn ($r) => $this->presentRow($r, $context) + [
            'duration_hours' => round((strtotime($r->completed_at.' UTC') - strtotime($r->started_at.' UTC')) / 3600, 1),
        ]);
    }
}
