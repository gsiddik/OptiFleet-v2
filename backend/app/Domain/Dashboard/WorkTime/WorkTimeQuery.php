<?php

namespace App\Domain\Dashboard\WorkTime;

use App\Domain\Dashboard\DashboardContext;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Work time and mechanic cost from the auditable work intervals (owner decisions 1–4, 9):
 *  - an interval is IN_PROGRESS time only (QC, hold and waiting for parts are excluded); an interval
 *    still open counts up to "now" and belongs to the current period;
 *  - an interval's hours belong to the month it ends (local time zone);
 *  - each mechanic gets the overlap of the interval with their own assignment windows (man-hours);
 *    overlapping assignments of the same mechanic on one Work Order (several jobs) are counted once,
 *    at the rate of the most recent assignment active at that moment;
 *  - cost = seconds × assignment hourly_rate_snapshot / 3600, rounded to 2 decimals per
 *    (interval, mechanic) row — every total is the sum of those rows, so drill-downs reconcile.
 * A row whose time is (partly) covered by an assignment without a rate has cost null ("rate missing").
 */
final class WorkTimeQuery
{
    /**
     * Intervals of the given Work Orders whose effective end (ended_at, or now when open) lies in
     * [fromUtc, toUtc) when a range is given.
     *
     * @param  Builder  $workOrderIds  a query selecting work order ids (already tenant/scope filtered)
     * @return Collection<int, object{id: string, work_order_id: string, cycle: int, started_at: int, ended_at: int, open: bool}>
     */
    public static function intervals(DashboardContext $context, Builder $workOrderIds, ?string $fromUtc = null, ?string $toUtc = null): Collection
    {
        $now = $context->now->utc()->format('Y-m-d H:i:s');
        $end = 'coalesce(i.ended_at, ?::timestamp)';

        return DB::table('work_order_work_intervals as i')
            ->where('i.tenant_id', $context->tenantId)
            ->whereIn('i.work_order_id', $workOrderIds)
            ->when($fromUtc !== null, fn ($q) => $q->whereRaw("{$end} >= ?::timestamp", [$now, $fromUtc]))
            ->when($toUtc !== null, fn ($q) => $q->whereRaw("{$end} < ?::timestamp", [$now, $toUtc]))
            ->orderBy('i.started_at')
            ->get(['i.id', 'i.work_order_id', 'i.cycle', 'i.started_at', 'i.ended_at'])
            ->map(fn ($r) => (object) [
                'id' => $r->id,
                'work_order_id' => $r->work_order_id,
                'cycle' => (int) $r->cycle,
                'started_at' => self::epoch($r->started_at),
                'ended_at' => $r->ended_at !== null ? self::epoch($r->ended_at) : $context->now->utc()->getTimestamp(),
                'open' => $r->ended_at === null,
            ]);
    }

    /**
     * Per (interval, mechanic) attribution.
     *
     * @param  Collection<int, object>  $intervals  from intervals()
     * @return list<array{interval_id: string, work_order_id: string, worker_id: string, ended_at: int, open: bool, seconds: int, cost: ?string}>
     */
    public static function attribute(DashboardContext $context, Collection $intervals): array
    {
        if ($intervals->isEmpty()) {
            return [];
        }
        $now = $context->now->utc()->getTimestamp();
        $assignments = DB::table('work_order_mechanic_assignments')
            ->whereIn('work_order_id', $intervals->pluck('work_order_id')->unique()->values())
            ->get(['work_order_id', 'worker_id', 'assigned_at', 'unassigned_at', 'hourly_rate_snapshot'])
            ->map(fn ($a) => (object) [
                'work_order_id' => $a->work_order_id,
                'worker_id' => $a->worker_id,
                'from' => self::epoch($a->assigned_at),
                'to' => $a->unassigned_at !== null ? self::epoch($a->unassigned_at) : $now,
                'rate' => $a->hourly_rate_snapshot,
            ])
            ->filter(fn ($a) => $a->to > $a->from)
            ->groupBy(fn ($a) => $a->work_order_id.'|'.$a->worker_id);

        $rows = [];
        foreach ($intervals as $interval) {
            foreach ($assignments as $key => $windows) {
                [$workOrderId, $workerId] = explode('|', $key);
                if ($workOrderId !== $interval->work_order_id) {
                    continue;
                }
                $row = self::overlap($interval, $windows);
                if ($row['seconds'] > 0) {
                    $rows[] = [
                        'interval_id' => $interval->id, 'work_order_id' => $workOrderId, 'worker_id' => $workerId,
                        'ended_at' => $interval->ended_at, 'open' => $interval->open,
                    ] + $row;
                }
            }
        }

        return $rows;
    }

    /**
     * Work Orders whose work history is complete: the first recorded interval starts exactly when
     * the Work Order was started (both are written in the same transition).
     *
     * @param  list<string>  $workOrderIds
     * @return array<string, bool>
     */
    public static function historyComplete(DashboardContext $context, array $workOrderIds): array
    {
        if ($workOrderIds === []) {
            return [];
        }
        $rows = DB::table('work_orders as wo')
            ->leftJoinSub(DB::table('work_order_work_intervals')->where('tenant_id', $context->tenantId)
                ->groupBy('work_order_id')->selectRaw('work_order_id, min(started_at) as first_start'), 'f', 'f.work_order_id', '=', 'wo.id')
            ->where('wo.tenant_id', $context->tenantId)->whereIn('wo.id', $workOrderIds)
            ->get(['wo.id', 'wo.started_at', 'f.first_start']);

        return $rows->mapWithKeys(fn ($r) => [$r->id => $r->first_start !== null && $r->started_at !== null && self::epoch($r->first_start) === self::epoch($r->started_at)])->all();
    }

    /** Seconds → hours, 2 decimals (display / averages only; costs use seconds). */
    public static function hours(int $seconds): string
    {
        return (string) BigDecimal::of($seconds)->dividedBy(3600, 2, RoundingMode::HALF_UP);
    }

    /** @return array{seconds: int, cost: ?string} */
    private static function overlap(object $interval, Collection $windows): array
    {
        $points = [$interval->started_at, $interval->ended_at];
        foreach ($windows as $w) {
            $points[] = max($interval->started_at, min($interval->ended_at, $w->from));
            $points[] = max($interval->started_at, min($interval->ended_at, $w->to));
        }
        $points = array_values(array_unique($points));
        sort($points);

        $seconds = 0;
        $cost = BigDecimal::zero();
        $rateMissing = false;
        for ($i = 0; $i < count($points) - 1; $i++) {
            [$t0, $t1] = [$points[$i], $points[$i + 1]];
            $active = $windows->filter(fn ($w) => $w->from <= $t0 && $w->to >= $t1)->sortByDesc('from')->first();
            if ($active === null) {
                continue;
            }
            $seconds += $t1 - $t0;
            if ($active->rate === null) {
                $rateMissing = true;
            } else {
                $cost = $cost->plus(BigDecimal::of((string) $active->rate)->multipliedBy($t1 - $t0));
            }
        }

        return [
            'seconds' => $seconds,
            'cost' => $rateMissing ? null : (string) $cost->dividedBy(3600, 2, RoundingMode::HALF_UP),
        ];
    }

    private static function epoch(string $timestamp): int
    {
        return CarbonImmutable::parse($timestamp, 'UTC')->getTimestamp();
    }
}
