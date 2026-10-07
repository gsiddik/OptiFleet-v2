<?php

namespace App\Domain\Dashboard\Widgets\Workshop;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\DataBasis;
use App\Domain\Dashboard\WorkTime\WorkTimeQuery;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * WS-08 Rework / QC first-pass rate — internal Work Orders completed in the period (completed_at,
 * tenant-local month) with a complete work history: first pass = no rework cycle (every work
 * interval in cycle 1); rework cycles = highest cycle − 1. Work Orders started before work time was
 * recorded cannot be judged and are excluded (reported). Per month and per workshop.
 */
class ReworkRateWidget extends WorkOrderWidget
{
    public function id(): string
    {
        return 'WS-08';
    }

    public function kind(): string
    {
        return self::KIND_PERIOD;
    }

    public function unit(): string
    {
        return 'mixed';
    }

    public function filters(): array
    {
        return ['branch', 'workshop', 'period'];
    }

    public function version(): int
    {
        return 2; // completeness basis
    }

    public function compute(DashboardContext $context): array
    {
        $rows = $this->rows($context);
        $valid = $rows->where('history_complete', true);
        $byMonth = $valid->groupBy('month');
        $summary = fn (Collection $set) => [
            'completed' => $set->count(),
            'first_pass' => $set->where('rework_cycles', 0)->count(),
            'with_rework' => $set->where('rework_cycles', '>', 0)->count(),
            'rework_cycles' => (int) $set->sum('rework_cycles'),
            'first_pass_rate' => $set->isEmpty() ? null : round($set->where('rework_cycles', 0)->count() * 100 / $set->count(), 1),
        ];

        $limitations = [];
        $excluded = $rows->where('history_complete', false)->count();
        if ($excluded > 0) {
            $limitations[] = ['code' => 'dashboard.limitations.reworkHistoryIncomplete', 'params' => ['n' => $excluded]];
        }

        return [
            'basis' => DataBasis::make(
                [['key' => 'SAMPLE', 'code' => 'WORK_ORDER_COMPLETED_DATE']],
                ['COMPLETED_INTERNAL_WORK_ORDERS_WITH_COMPLETE_HISTORY'],
                ['EXTERNAL_WORK_ORDERS', 'WORK_ORDERS_WITHOUT_COMPLETE_WORK_TIME_HISTORY'],
                DataBasis::completeness($rows->count(), $valid->count(), ['WORK_TIME_HISTORY_INCOMPLETE' => $excluded]),
                \App\Domain\Dashboard\WorkTime\WorkTimeQuery::historyAvailableFrom($context),
            ),
            'data' => [
                'totals' => $summary($valid),
                'months' => array_map(fn ($m) => ['month' => $m, 'is_current' => $m === $context->currentMonth()] + $summary($byMonth[$m] ?? collect()), $context->months()),
                'workshops' => $valid->groupBy('workshop_id')->map(fn ($set, $id) => ['workshop_id' => $id, 'workshop_name' => $set->first()['workshop_name']] + $summary($set))
                    ->sortByDesc('completed')->values()->all(),
            ],
            'limitations' => $limitations,
        ];
    }

    public function detailRules(): ?array
    {
        return ['month' => ['nullable', 'date_format:Y-m'], 'workshop_id' => ['nullable', 'uuid'], 'rework_only' => ['nullable', 'boolean']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $rows = $this->rows($context)->where('history_complete', true)
            ->when($params['month'] ?? null, fn ($c, $m) => $c->where('month', $m))
            ->when($params['workshop_id'] ?? null, fn ($c, $id) => $c->where('workshop_id', $id))
            ->when(! empty($params['rework_only']), fn ($c) => $c->where('rework_cycles', '>', 0))
            ->sortBy([['rework_cycles', 'desc'], ['completed_at', 'desc']])->values()->all();

        return $this->paginateList($rows, $params);
    }

    private function rows(DashboardContext $context): Collection
    {
        [$fromUtc, $toUtc] = $context->utcBounds($context->periodStartDate(), $context->periodEndDateExclusive());
        $query = $this->workOrders($context)->where('wo.execution_mode', '!=', 'EXTERNAL')->whereIn('wo.status', ['COMPLETED', 'CLOSED'])
            ->where('wo.completed_at', '>=', $fromUtc)->where('wo.completed_at', '<', $toUtc);
        $cycles = fn (Builder $ids) => DB::table('work_order_work_intervals')->where('tenant_id', $context->tenantId)->whereIn('work_order_id', $ids)
            ->groupBy('work_order_id')->selectRaw('work_order_id, max(cycle) as c')->pluck('c', 'work_order_id');
        $max = $cycles((clone $query)->select('wo.id'));
        $list = $this->withListColumns(clone $query)->addSelect(['wo.workshop_id', DB::raw($context->localMonthSql('wo.completed_at').' as month')])->get();
        $complete = WorkTimeQuery::historyComplete($context, $list->pluck('id')->all());

        return $list->map(fn ($r) => [
            'id' => $r->id, 'wo_number' => $r->wo_number, 'maintenance_type' => $r->maintenance_type,
            'vehicle_id' => $r->vehicle_id, 'registration_number' => $r->registration_number,
            'workshop_id' => $r->workshop_id, 'workshop_name' => $r->workshop_name, 'month' => $r->month,
            'completed_at' => self::isoUtc($r->completed_at),
            'rework_cycles' => max(0, (int) ($max[$r->id] ?? 1) - 1),
            'history_complete' => $complete[$r->id] ?? false,
        ]);
    }
}
