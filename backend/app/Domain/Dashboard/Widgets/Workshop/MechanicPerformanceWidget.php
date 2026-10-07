<?php

namespace App\Domain\Dashboard\Widgets\Workshop;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\DashboardPermissions;
use App\Domain\Dashboard\DataBasis;
use App\Domain\Dashboard\Models\MechanicPerformanceBaseline;
use App\Domain\Dashboard\WorkTime\WorkTimeQuery;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * WS-07 Mechanic Performance (owner decisions 9–12), for one maintenance type at a time (the baseline
 * is per type, so mechanics are only compared on like-for-like work):
 *  - WO handled   = internal Work Orders whose work intervals ending in the period overlap the
 *                   mechanic's assignments (running Work Orders included, counted only);
 *  - WO completed = Work Orders COMPLETED/CLOSED in the period (completed_at) the mechanic worked on;
 *  - valid sample = a completed Work Order whose work history is complete (recorded from its start);
 *  - average      = mean of the mechanic's own work hours per valid sample (all its intervals);
 *  - status       = NO_BASELINE (none set) / INSUFFICIENT_SAMPLE (< 5 valid samples) / MEETS
 *                   (average ≤ baseline) / ABOVE.
 * A higher average is not by itself a worse mechanic: job mix within a type still differs.
 */
class MechanicPerformanceWidget extends WorkOrderWidget
{
    public const MIN_SAMPLES = 5;

    public function id(): string
    {
        return 'WS-07';
    }

    public function kind(): string
    {
        return self::KIND_PERIOD;
    }

    public function unit(): string
    {
        return 'mixed';
    }

    public function permissions(): array
    {
        return ['work_order.view', 'worker.view'];
    }

    public function filters(): array
    {
        return ['branch', 'workshop', 'period'];
    }

    public function paramRules(): array
    {
        return ['maintenance_type' => ['nullable', Rule::in(MechanicPerformanceBaseline::MAINTENANCE_TYPES)]];
    }

    public function version(): int
    {
        return 2; // exclusion counts, unavailable average, completeness basis
    }

    public function compute(DashboardContext $context): array
    {
        $stats = $this->stats($context);
        $type = $context->filters->param('maintenance_type') ?? $this->defaultType($stats);
        $baselines = $this->baselines($context);
        $baseline = $baselines[$type] ?? null;

        $mechanics = $stats->where('type', $type)->map(fn ($s) => $this->present($s, $baseline))
            ->sortBy([['valid_samples', 'desc'], ['worker_name', 'asc']])->values()->all();

        $forType = $stats->where('type', $type);
        $reasons = ['WORK_TIME_UNAVAILABLE' => (int) $forType->sum('excluded_unavailable'), 'WORK_TIME_PARTIAL' => (int) $forType->sum('excluded_partial'), 'NO_ATTRIBUTED_TIME' => (int) $forType->sum('excluded_no_time')];
        $completeness = DataBasis::completeness((int) $forType->sum('completed_total'), (int) $forType->sum('valid_samples'), $reasons);

        return ['basis' => DataBasis::make(
            [['key' => 'SAMPLE', 'code' => 'WORK_ORDER_COMPLETED_DATE'], ['key' => 'HOURS', 'code' => 'WORK_INTERVALS']],
            ['COMPLETED_INTERNAL_WORK_ORDERS_OF_TYPE', 'MECHANIC_OWN_HOURS'],
            ['EXTERNAL_WORK_ORDERS', 'WORK_ORDERS_WITHOUT_COMPLETE_WORK_TIME_HISTORY', 'ESTIMATED_TIME'],
            $completeness,
            WorkTimeQuery::historyAvailableFrom($context),
        ), 'data' => [
            'maintenance_type' => $type,
            'baseline_hours' => $baseline,
            'min_samples' => self::MIN_SAMPLES,
            'can_manage_baseline' => $context->can(DashboardPermissions::BASELINE_MANAGE),
            'types' => array_map(fn ($t) => [
                'maintenance_type' => $t, 'baseline_hours' => $baselines[$t] ?? null,
                'valid_samples' => (int) $stats->where('type', $t)->sum('valid_samples'),
                'completed_total' => (int) $stats->where('type', $t)->sum('completed_total'),
            ], MechanicPerformanceBaseline::MAINTENANCE_TYPES),
            'completeness' => $completeness,
            'mechanics' => $mechanics,
        ]];
    }

    public function detailRules(): ?array
    {
        return ['worker_id' => ['required', 'uuid']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $type = $context->filters->param('maintenance_type');
        $rows = collect(WorkTimeQuery::attribute($context, WorkTimeQuery::intervals($context, $this->completed($context, $type)->select('wo.id'))))
            ->where('worker_id', $params['worker_id'])->groupBy('work_order_id');
        // Every completed Work Order the mechanic was assigned to: those without recorded work time are listed too
        // (hours unavailable, not 0), so the list adds up to the completed total and shows why a sample is excluded.
        $assigned = DB::table('work_order_mechanic_assignments')->where('worker_id', $params['worker_id'])
            ->whereIn('work_order_id', $this->completed($context, $type)->select('wo.id'))->distinct()->pluck('work_order_id')->all();
        $ids = array_values(array_unique(array_merge($rows->keys()->all(), $assigned)));
        $state = WorkTimeQuery::historyState($context, $ids);
        $cycles = DB::table('work_order_work_intervals')->whereIn('work_order_id', $ids)->groupBy('work_order_id')
            ->selectRaw('work_order_id, max(cycle) as c')->pluck('c', 'work_order_id');
        $info = $this->withListColumns(DB::table('work_orders as wo')->whereIn('wo.id', $ids))->get()->keyBy('id');

        $items = collect($ids)->map(fn ($id) => [
            'work_order_id' => $id, 'wo_number' => $info[$id]->wo_number, 'maintenance_type' => $info[$id]->maintenance_type,
            'vehicle_id' => $info[$id]->vehicle_id, 'registration_number' => $info[$id]->registration_number,
            'completed_at' => self::isoUtc($info[$id]->completed_at),
            'worker_hours' => isset($rows[$id]) ? WorkTimeQuery::hours((int) $rows[$id]->sum('seconds')) : null,
            'rework_cycles' => isset($cycles[$id]) ? max(0, (int) $cycles[$id] - 1) : null,
            'history_complete' => ($state[$id] ?? null) === WorkTimeQuery::COMPLETE,
            'work_time_state' => $state[$id] ?? WorkTimeQuery::UNAVAILABLE,
            'valid_sample' => ($state[$id] ?? null) === WorkTimeQuery::COMPLETE && isset($rows[$id]),
        ])->sortByDesc('completed_at')->values()->all();

        return $this->paginateList($items, $params);
    }

    /** Per (mechanic, maintenance type): handled / completed / valid samples / sum of seconds over valid samples. */
    private function stats(DashboardContext $context): Collection
    {
        [$fromUtc, $toUtc] = $context->utcBounds($context->periodStartDate(), $context->periodEndDateExclusive());
        $types = fn ($ids) => DB::table('work_orders')->whereIn('id', $ids)->pluck('maintenance_type', 'id');

        $handled = collect(WorkTimeQuery::attribute($context, WorkTimeQuery::intervals($context, $this->internal($context)->select('wo.id'), $fromUtc, $toUtc)));
        $done = collect(WorkTimeQuery::attribute($context, WorkTimeQuery::intervals($context, $this->completed($context, null)->select('wo.id'))));
        $typeOf = $types($handled->pluck('work_order_id')->merge($done->pluck('work_order_id'))->unique()->values());
        $complete = WorkTimeQuery::historyComplete($context, $done->pluck('work_order_id')->unique()->values()->all());

        // Population = completed Work Orders the mechanic was assigned to (with or without recorded time), so Work Orders
        // that have no work-time history are counted as excluded instead of silently disappearing.
        $completedIds = $this->completed($context, null)->pluck('wo.id')->all();
        $assigned = $completedIds === [] ? collect() : DB::table('work_order_mechanic_assignments')->whereIn('work_order_id', $completedIds)
            ->select('worker_id', 'work_order_id')->distinct()->get();
        $typeOf = $typeOf->merge(DB::table('work_orders')->whereIn('id', $assigned->pluck('work_order_id')->unique()->values())->pluck('maintenance_type', 'id'));
        $state = WorkTimeQuery::historyState($context, $assigned->pluck('work_order_id')->unique()->values()->all());

        $keys = $handled->concat($done)->map(fn ($r) => $r['worker_id'].'|'.$typeOf[$r['work_order_id']])
            ->concat($assigned->map(fn ($a) => $a->worker_id.'|'.$typeOf[$a->work_order_id]))->unique();
        $workers = DB::table('workers as w')->leftJoin('workshops as s', 's.id', '=', 'w.workshop_id')
            ->where('w.tenant_id', $context->tenantId)->whereIn('w.id', $keys->map(fn ($k) => explode('|', $k)[0])->unique()->values())
            ->get(['w.id', 'w.name', 'w.employee_code', 's.name as workshop_name'])->keyBy('id');

        return $keys->map(function (string $key) use ($handled, $done, $typeOf, $complete, $workers, $assigned, $state) {
            [$workerId, $type] = explode('|', $key);
            $mine = fn (Collection $rows) => $rows->where('worker_id', $workerId)->filter(fn ($r) => $typeOf[$r['work_order_id']] === $type);
            $perWo = $mine($done)->groupBy('work_order_id')->map(fn ($rows) => (int) $rows->sum('seconds'));
            $valid = $perWo->filter(fn ($seconds, $id) => $complete[$id] ?? false);
            $population = $assigned->where('worker_id', $workerId)->pluck('work_order_id')->unique()->filter(fn ($id) => $typeOf[$id] === $type)->values();
            $excluded = fn (string $s) => $population->filter(fn ($id) => ($state[$id] ?? null) === $s)->count();

            return [
                'worker_id' => $workerId, 'type' => $type,
                'worker_name' => $workers[$workerId]->name ?? null, 'employee_code' => $workers[$workerId]->employee_code ?? null,
                'workshop_name' => $workers[$workerId]->workshop_name ?? null,
                'wo_handled' => $mine($handled)->pluck('work_order_id')->unique()->count(),
                'wo_completed' => $perWo->count(),
                'valid_samples' => $valid->count(),
                'valid_seconds' => (int) $valid->sum(),
                'completed_total' => $population->count(),
                'excluded_unavailable' => $excluded(WorkTimeQuery::UNAVAILABLE),
                'excluded_partial' => $excluded(WorkTimeQuery::PARTIAL),
                'excluded_no_time' => max(0, $population->count() - $valid->count() - $excluded(WorkTimeQuery::UNAVAILABLE) - $excluded(WorkTimeQuery::PARTIAL)),
            ];
        })->values();
    }

    private function present(array $s, ?string $baseline): array
    {
        $avg = $s['valid_samples'] > 0
            ? BigDecimal::of($s['valid_seconds'])->dividedBy($s['valid_samples'] * 3600, 2, RoundingMode::HALF_UP) : null;
        $status = match (true) {
            $baseline === null => 'NO_BASELINE',
            $s['valid_samples'] < self::MIN_SAMPLES => 'INSUFFICIENT_SAMPLE',
            $avg->compareTo($baseline) <= 0 => 'MEETS',
            default => 'ABOVE',
        };

        return [
            'worker_id' => $s['worker_id'], 'worker_name' => $s['worker_name'], 'employee_code' => $s['employee_code'], 'workshop_name' => $s['workshop_name'],
            'wo_handled' => $s['wo_handled'], 'wo_completed' => $s['wo_completed'], 'valid_samples' => $s['valid_samples'],
            'completed_total' => $s['completed_total'], 'excluded_samples' => $s['completed_total'] - $s['valid_samples'],
            'excluded_reasons' => ['WORK_TIME_UNAVAILABLE' => $s['excluded_unavailable'], 'WORK_TIME_PARTIAL' => $s['excluded_partial'], 'NO_ATTRIBUTED_TIME' => $s['excluded_no_time']],
            // No valid sample → the average is unavailable (null), never 0.
            'avg_state' => $avg === null ? 'UNAVAILABLE' : 'AVAILABLE',
            'avg_hours' => $avg === null ? null : (string) $avg,
            'baseline_hours' => $baseline,
            'diff_hours' => $avg !== null && $baseline !== null ? (string) $avg->minus($baseline)->toScale(2) : null,
            'ratio' => $avg !== null && $baseline !== null ? (string) $avg->dividedBy($baseline, 2, RoundingMode::HALF_UP) : null,
            'status' => $status,
        ];
    }

    private function defaultType(Collection $stats): string
    {
        $best = null;
        foreach (MechanicPerformanceBaseline::MAINTENANCE_TYPES as $type) {
            $n = (int) $stats->where('type', $type)->sum('valid_samples') * 1000 + (int) $stats->where('type', $type)->sum('wo_handled');
            if ($best === null || $n > $best[1]) {
                $best = [$type, $n];
            }
        }

        return $best[0];
    }

    /** @return array<string, string> maintenance type → baseline hours (2 dp) */
    private function baselines(DashboardContext $context): array
    {
        return DB::table('mechanic_performance_baselines')->where('tenant_id', $context->tenantId)->pluck('baseline_hours', 'maintenance_type')
            ->map(fn ($h) => (string) BigDecimal::of((string) $h)->toScale(2))->all();
    }

    private function internal(DashboardContext $context): Builder
    {
        return $this->workOrders($context)->where('wo.execution_mode', '!=', 'EXTERNAL');
    }

    private function completed(DashboardContext $context, ?string $type): Builder
    {
        [$fromUtc, $toUtc] = $context->utcBounds($context->periodStartDate(), $context->periodEndDateExclusive());

        return $this->internal($context)->whereIn('wo.status', ['COMPLETED', 'CLOSED'])
            ->where('wo.completed_at', '>=', $fromUtc)->where('wo.completed_at', '<', $toUtc)
            ->when($type, fn ($q) => $q->where('wo.maintenance_type', $type));
    }
}
