<?php

namespace App\Domain\Dashboard\Widgets\Warehouse;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * WH-07 Part fulfilment lead time —
 *  - Part Requests issued in the period (tenant-local month of issued_at): hours from requested_at to
 *    issued_at, median and 90th percentile per month and per issuing warehouse (warehouse scope);
 *  - still open requests (requested, not issued) with their current age;
 *  - with work_order.view: time Work Orders spent in WAITING_PART, from the work intervals (an episode
 *    starts when an interval closes into WAITING_PART and ends when work resumes, or now) — only
 *    available for Work Orders worked on since work time is recorded.
 */
class PartFulfilmentWidget extends Widget
{
    public function id(): string
    {
        return 'WH-07';
    }

    public function kind(): string
    {
        return self::KIND_PERIOD;
    }

    public function unit(): string
    {
        return 'mixed';
    }

    public function modules(): array
    {
        return ['INVENTORY', 'WORK_ORDER'];
    }

    public function permissions(): array
    {
        return ['inventory.view'];
    }

    public function filters(): array
    {
        return ['branch', 'warehouse', 'period'];
    }

    public function compute(DashboardContext $context): array
    {
        $stats = fn (Builder $q) => $q->selectRaw('count(*) as requests,
            percentile_cont(0.5) within group (order by r.hours) as median_hours, percentile_cont(0.9) within group (order by r.hours) as p90_hours');
        $hours = fn ($v) => $v === null ? null : round((float) $v, 1);
        $present = fn ($r) => ['requests' => (int) $r->requests, 'median_hours' => $hours($r->median_hours), 'p90_hours' => $hours($r->p90_hours)];

        $byMonth = $stats($this->issued($context))->addSelect('r.month')->groupBy('r.month')->get()->keyBy('month');
        $open = $this->open($context)->selectRaw('count(*) as c, max(extract(epoch from (?::timestamp - pr.requested_at)) / 3600) as oldest', [$context->nowUtc()])->first();

        return ['data' => [
            'totals' => $present($stats($this->issued($context))->first()),
            'months' => array_map(fn ($m) => ['month' => $m, 'is_current' => $m === $context->currentMonth()]
                + (isset($byMonth[$m]) ? $present($byMonth[$m]) : ['requests' => 0, 'median_hours' => null, 'p90_hours' => null]), $context->months()),
            'warehouses' => $stats($this->issued($context))->addSelect(['r.warehouse_id', 'r.warehouse_name'])->groupBy('r.warehouse_id', 'r.warehouse_name')
                ->orderByDesc('requests')->get()->map(fn ($r) => ['warehouse_id' => $r->warehouse_id, 'warehouse_name' => $r->warehouse_name] + $present($r))->all(),
            'open' => ['requests' => (int) $open->c, 'oldest_hours' => $hours($open->oldest)],
            'waiting_part' => $context->can('work_order.view') ? $this->waitingPart($context) : null,
        ]];
    }

    public function detailRules(): ?array
    {
        return ['month' => ['nullable', 'date_format:Y-m'], 'open' => ['nullable', 'boolean']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        if (! empty($params['open'])) {
            $query = $this->open($context)->leftJoin('work_orders as wo', 'wo.id', '=', 'pr.work_order_id')->leftJoin('warehouses as w', 'w.id', '=', 'pr.warehouse_id')
                ->orderBy('pr.requested_at')->select(['pr.id', 'pr.status', 'pr.requested_at', 'wo.id as work_order_id', 'wo.wo_number', 'w.name as warehouse_name'])
                ->selectRaw('extract(epoch from (?::timestamp - pr.requested_at)) / 3600 as age_hours', [$context->nowUtc()]);

            return $this->paginate($query, $params, fn ($r) => [
                'id' => $r->id, 'status' => $r->status, 'requested_at' => self::isoUtc($r->requested_at),
                'work_order_id' => $r->work_order_id, 'wo_number' => $r->wo_number, 'warehouse_name' => $r->warehouse_name,
                'age_hours' => round((float) $r->age_hours, 1),
            ]);
        }
        $query = $this->issued($context)->when($params['month'] ?? null, fn ($q, $m) => $q->where('r.month', $m))->orderByDesc('r.hours')->select('r.*');

        return $this->paginate($query, $params, fn ($r) => [
            'id' => $r->id, 'work_order_id' => $r->work_order_id, 'wo_number' => $r->wo_number,
            'warehouse_name' => $r->warehouse_name, 'requested_at' => self::isoUtc($r->requested_at), 'issued_at' => self::isoUtc($r->issued_at),
            'hours' => round((float) $r->hours, 1),
        ]);
    }

    private function issued(DashboardContext $context): Builder
    {
        [$fromUtc, $toUtc] = $context->utcBounds($context->periodStartDate(), $context->periodEndDateExclusive());
        $query = DB::table('work_order_part_requests as pr')->join('work_orders as wo', 'wo.id', '=', 'pr.work_order_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'pr.warehouse_id')
            ->where('pr.tenant_id', $context->tenantId)->whereNotNull('pr.requested_at')->whereNotNull('pr.issued_at')
            ->where('pr.issued_at', '>=', $fromUtc)->where('pr.issued_at', '<', $toUtc)
            ->selectRaw('pr.id, pr.warehouse_id, w.name as warehouse_name, wo.id as work_order_id, wo.wo_number, pr.requested_at, pr.issued_at,
                '.$context->localMonthSql('pr.issued_at').' as month, extract(epoch from (pr.issued_at - pr.requested_at)) / 3600 as hours');
        $context->scopeWarehouse($query, 'pr.warehouse_id');

        return DB::query()->fromSub($query, 'r');
    }

    private function open(DashboardContext $context): Builder
    {
        $query = DB::table('work_order_part_requests as pr')->where('pr.tenant_id', $context->tenantId)
            ->whereNotNull('pr.requested_at')->whereNull('pr.issued_at')->whereIn('pr.status', ['REQUESTED', 'APPROVED']);

        return $context->scopeWarehouse($query, 'pr.warehouse_id');
    }

    /** WAITING_PART episodes ending (or still running) in the period, from the work intervals. */
    private function waitingPart(DashboardContext $context): array
    {
        [$fromUtc, $toUtc] = $context->utcBounds($context->periodStartDate(), $context->periodEndDateExclusive());
        $workOrders = DB::table('work_orders as wo')->where('wo.tenant_id', $context->tenantId)->whereNull('wo.deleted_at');
        $context->scopeWorkOrder($workOrders, 'wo.workshop_id', 'wo.branch_id');
        $episodes = DB::table('work_order_work_intervals as i')->where('i.tenant_id', $context->tenantId)->where('i.end_to_status', 'WAITING_PART')
            ->whereIn('i.work_order_id', $workOrders->select('wo.id'))
            ->selectRaw('i.work_order_id, i.ended_at as started, coalesce((select min(n.started_at) from work_order_work_intervals n
                where n.work_order_id = i.work_order_id and n.started_at >= i.ended_at), ?::timestamp) as ended', [$context->nowUtc()]);
        $row = DB::query()->fromSub($episodes, 'e')->where('e.ended', '>=', $fromUtc)->where('e.ended', '<', $toUtc)
            ->selectRaw('count(*) as episodes, count(distinct e.work_order_id) as work_orders, sum(extract(epoch from (e.ended - e.started))) / 3600 as hours,
                percentile_cont(0.5) within group (order by extract(epoch from (e.ended - e.started)) / 3600) as median_hours')->first();

        return ['episodes' => (int) $row->episodes, 'work_orders' => (int) $row->work_orders,
            'hours' => $row->hours === null ? 0.0 : round((float) $row->hours, 1), 'median_hours' => $row->median_hours === null ? null : round((float) $row->median_hours, 1)];
    }
}
