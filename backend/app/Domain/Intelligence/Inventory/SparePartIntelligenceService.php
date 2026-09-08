<?php

namespace App\Domain\Intelligence\Inventory;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 Section 32: statistical evidence about spare-part consumption
 * — frequently replaced parts, consumption trend, unusual consumption.
 * Computed on demand directly from the Phase 4 stock_movements ledger
 * (Section 79: reuse existing infrastructure) rather than a parallel
 * daily-snapshot collection; consumption analytics at this volume don't
 * need a materialized projection the way per-vehicle daily features do.
 */
class SparePartIntelligenceService
{
    /** @return array<int, array{product_id: string, quantity_consumed: float, movement_count: int, trend_pct: ?float}> */
    public function topConsumedParts(string $tenantId, int $days = 90, int $limit = 20): array
    {
        $now = CarbonImmutable::now();
        $windowStart = $now->subDays($days);
        $halfway = $now->subDays((int) ($days / 2));

        $rows = DB::table('stock_movements')
            ->where('tenant_id', $tenantId)
            ->where('movement_type', 'ISSUE')
            ->whereBetween('occurred_at', [$windowStart, $now])
            ->select('product_id', DB::raw('sum(abs(quantity)) as total_qty'), DB::raw('count(*) as movement_count'))
            ->groupBy('product_id')
            ->orderByDesc('total_qty')
            ->limit($limit)
            ->get();

        return $rows->map(function ($row) use ($tenantId, $halfway, $now, $windowStart) {
            $recent = (float) DB::table('stock_movements')->where('tenant_id', $tenantId)->where('product_id', $row->product_id)
                ->where('movement_type', 'ISSUE')->whereBetween('occurred_at', [$halfway, $now])->sum(DB::raw('abs(quantity)'));
            $prior = (float) DB::table('stock_movements')->where('tenant_id', $tenantId)->where('product_id', $row->product_id)
                ->where('movement_type', 'ISSUE')->whereBetween('occurred_at', [$windowStart, $halfway])->sum(DB::raw('abs(quantity)'));

            return [
                'product_id' => $row->product_id,
                'quantity_consumed' => (float) $row->total_qty,
                'movement_count' => (int) $row->movement_count,
                'trend_pct' => $prior > 0 ? round((($recent - $prior) / $prior) * 100, 1) : null,
            ];
        })->all();
    }

    /** Parts consumed against a given vehicle's Work Orders — Section 32 "part usage per vehicle model". */
    public function partsForVehicle(string $vehicleId, int $days = 180): array
    {
        return DB::table('stock_movements as sm')
            ->join('work_orders as wo', function ($join) {
                $join->on('sm.reference_id', '=', 'wo.id')->where('sm.reference_type', '=', 'WORK_ORDER');
            })
            ->where('wo.vehicle_id', $vehicleId)
            ->where('sm.movement_type', 'ISSUE')
            ->where('sm.occurred_at', '>=', CarbonImmutable::now()->subDays($days))
            ->select('sm.product_id', DB::raw('sum(abs(sm.quantity)) as total_qty'), DB::raw('count(distinct wo.id) as work_order_count'))
            ->groupBy('sm.product_id')
            ->orderByDesc('total_qty')
            ->get()
            ->map(fn ($r) => ['product_id' => $r->product_id, 'quantity_consumed' => (float) $r->total_qty, 'work_order_count' => (int) $r->work_order_count])
            ->all();
    }
}
