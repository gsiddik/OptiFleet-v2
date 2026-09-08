<?php

namespace App\Domain\Intelligence\Inventory;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 Section 33: demand forecast FOUNDATION only — a moving-average
 * projection over historical consumption (Phase 4's stock_movements
 * ledger), never a purchase order. Existing Procurement flow remains
 * authoritative for any actual reorder action (Section 33, Section 2 —
 * no direct AI control over operational workflows).
 */
class InventoryDemandForecastService
{
    public function forecast(string $tenantId, string $productId, string $warehouseId, int $historyDays = 90, int $horizonDays = 30): array
    {
        $now = CarbonImmutable::now();
        $dailyQuantities = $this->dailyConsumption($tenantId, $productId, $warehouseId, $now->subDays($historyDays), $now);

        $n = count($dailyQuantities);
        $mean = $n > 0 ? array_sum($dailyQuantities) / $historyDays : 0.0; // zero-fill days with no movement
        $variance = $n > 0 ? array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $dailyQuantities)) / max(1, $n) : 0.0;
        $stddev = sqrt($variance);

        $expectedDemand = round($mean * $horizonDays, 2);
        $spread = round($stddev * sqrt($horizonDays), 2);

        $stock = DB::table('warehouse_stocks')->where('tenant_id', $tenantId)
            ->where('warehouse_id', $warehouseId)->where('product_id', $productId)->first();
        $onHand = (float) ($stock->quantity_on_hand ?? 0);
        $reorderPoint = (float) ($stock->reorder_point ?? 0);

        $projectedShortfall = ($onHand - $expectedDemand) < $reorderPoint;
        $suggestedReorderQty = $projectedShortfall ? round(max(0, $expectedDemand + $reorderPoint - $onHand), 2) : 0.0;

        return [
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'horizon_days' => $horizonDays,
            'history_days' => $historyDays,
            'expected_demand' => [
                'point' => $expectedDemand,
                'low' => max(0, round($expectedDemand - $spread, 2)),
                'high' => round($expectedDemand + $spread, 2),
            ],
            'current_stock_on_hand' => $onHand,
            'reorder_point' => $reorderPoint,
            'shortage_risk' => $projectedShortfall ? 'HIGH' : 'LOW',
            'suggested_reorder_quantity' => $suggestedReorderQty,
            'basis' => 'MOVING_AVERAGE',
            'source_data_as_of' => $now->toIso8601String(),
        ];
    }

    /** @return float[] daily consumed quantity for each day with at least one ISSUE movement (sparse) */
    private function dailyConsumption(string $tenantId, string $productId, string $warehouseId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('stock_movements')
            ->where('tenant_id', $tenantId)->where('product_id', $productId)->where('warehouse_id', $warehouseId)
            ->where('movement_type', 'ISSUE')->whereBetween('occurred_at', [$from, $to])
            ->select(DB::raw('date(occurred_at) as d'), DB::raw('sum(abs(quantity)) as qty'))
            ->groupBy(DB::raw('date(occurred_at)'))
            ->pluck('qty')
            ->map(fn ($v) => (float) $v)
            ->all();
    }
}
