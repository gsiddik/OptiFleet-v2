<?php

namespace App\Domain\Analytics\Extractors;

use App\Domain\Analytics\Contracts\DatasetExtractor;
use App\Domain\Analytics\Support\AnalyticsUpsertWriter;
use App\Domain\Analytics\Support\BusinessDateResolver;
use App\Domain\Analytics\Support\EtlDatasetResult;
use App\Domain\Identity\Models\Tenant;
use App\Domain\Organization\Models\Warehouse;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 6 Section 29-30 — daily_inventory_metrics, one document per
 * (tenant, snapshot_date, warehouse) plus a tenant-wide rollup.
 *
 * inventory_value = sum(quantity_on_hand * average_unit_cost) — reuses
 *   the warehouse_stocks weighted-average cost the Phase 4 valuation
 *   method already maintains (Section 30: never recompute historical
 *   cost from current prices).
 * stock_on_hand / stock_reserved = sum of the current balance columns —
 *   point-in-time, like the fleet snapshot (no stock-balance-history
 *   table exists; stock_movements is the ledger used for the window
 *   metrics below).
 * low_stock  = products with 0 < quantity_on_hand <= reorder_point.
 * out_of_stock = products with quantity_on_hand <= 0.
 * stock_movement = count + total quantity per movement_type with
 *   occurred_at in [start,end).
 * part_consumption = total ISSUE quantity in the window.
 * stock_variance = ADJUSTMENT_PLUS quantity - ADJUSTMENT_MINUS quantity
 *   in the window (net correction found by stock opname/adjustment).
 * inventory_turnover_foundation = issued_value_in_window /
 *   inventory_value — a same-day ratio, not an annualized turnover rate;
 *   trend/range queries over many days derive the conventional
 *   COGS/avg-inventory ratio from this foundation.
 * slow_moving / fast_moving = bounded (max 10, Section 54) product lists
 *   ranked by movement count over the trailing 30 days.
 */
class InventoryMetricsExtractor implements DatasetExtractor
{
    private const MOVING_LOOKBACK_DAYS = 30;

    private const TOP_N = 10;

    public function __construct(
        private readonly AnalyticsUpsertWriter $writer,
        private readonly BusinessDateResolver $businessDates,
    ) {}

    public function key(): string
    {
        return 'inventory_metrics';
    }

    public function label(): string
    {
        return 'Inventory Metrics';
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
        $movingWindowStart = $end->subDays(self::MOVING_LOOKBACK_DAYS);

        $warehouseIds = Warehouse::query()->withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('id');
        $result->sourceCount = DB::table('warehouse_stocks')->whereIn('warehouse_id', $warehouseIds)->count();

        $documents = [];
        $documents[] = $this->buildDocument($tenantId, $snapshotDate, null, $warehouseIds->all(), $start, $end, $movingWindowStart);
        foreach ($warehouseIds as $warehouseId) {
            $documents[] = $this->buildDocument($tenantId, $snapshotDate, $warehouseId, [$warehouseId], $start, $end, $movingWindowStart);
        }

        $result->processedCount = count($documents);
        $this->writer->upsertMany('daily_inventory_metrics', $documents, $result);

        return $result;
    }

    private function buildDocument(string $tenantId, string $snapshotDate, ?string $warehouseId, array $warehouseIds, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $movingWindowStart): array
    {
        $stocks = DB::table('warehouse_stocks')->whereIn('warehouse_id', $warehouseIds)
            ->get(['product_id', 'quantity_on_hand', 'quantity_reserved', 'reorder_point', 'average_unit_cost']);

        $inventoryValue = $stocks->sum(fn ($s) => $s->quantity_on_hand * $s->average_unit_cost);
        $lowStock = $stocks->filter(fn ($s) => $s->quantity_on_hand > 0 && $s->quantity_on_hand <= $s->reorder_point)->count();
        $outOfStock = $stocks->filter(fn ($s) => $s->quantity_on_hand <= 0)->count();

        $movementsByType = DB::table('stock_movements')->whereIn('warehouse_id', $warehouseIds)
            ->whereBetween('occurred_at', [$start, $end])
            ->select('movement_type', DB::raw('count(*) as cnt'), DB::raw('sum(quantity) as qty'))
            ->groupBy('movement_type')->get()->keyBy('movement_type');

        $issuedQty = (float) ($movementsByType['ISSUE']->qty ?? 0);
        $issuedValue = DB::table('stock_movements')->whereIn('warehouse_id', $warehouseIds)
            ->where('movement_type', 'ISSUE')->whereBetween('occurred_at', [$start, $end])
            ->sum(DB::raw('quantity * COALESCE(unit_cost, 0)'));

        $adjustmentPlus = (float) ($movementsByType['ADJUSTMENT_PLUS']->qty ?? 0);
        $adjustmentMinus = (float) ($movementsByType['ADJUSTMENT_MINUS']->qty ?? 0);

        $movementBreakdown = $movementsByType->map(fn ($row) => ['count' => (int) $row->cnt, 'quantity' => (float) $row->qty])->all();

        $movingCounts = DB::table('stock_movements')->whereIn('warehouse_id', $warehouseIds)
            ->whereBetween('occurred_at', [$movingWindowStart, $end])
            ->select('product_id', DB::raw('count(*) as cnt'))
            ->groupBy('product_id')->get();

        $movedProductIds = $movingCounts->pluck('product_id');
        $fastMoving = $movingCounts->sortByDesc('cnt')->take(self::TOP_N)
            ->map(fn ($row) => ['product_id' => $row->product_id, 'movement_count' => $row->cnt])->values()->all();

        $slowMoving = $stocks->whereNotIn('product_id', $movedProductIds)
            ->take(self::TOP_N)->map(fn ($s) => ['product_id' => $s->product_id])->values()->all();

        $doc = [
            'tenant_id' => $tenantId,
            'snapshot_date' => $snapshotDate,
            'warehouse_id' => $warehouseId,
            'inventory_value' => round($inventoryValue, 4),
            'stock_on_hand' => (float) $stocks->sum('quantity_on_hand'),
            'stock_reserved' => (float) $stocks->sum('quantity_reserved'),
            'low_stock_count' => $lowStock,
            'out_of_stock_count' => $outOfStock,
            'stock_movement' => $movementBreakdown,
            'part_consumption_quantity' => $issuedQty,
            'stock_adjustment' => [
                'plus_quantity' => $adjustmentPlus,
                'minus_quantity' => $adjustmentMinus,
            ],
            'stock_variance' => $adjustmentPlus - $adjustmentMinus,
            'inventory_turnover_foundation' => $inventoryValue > 0 ? round($issuedValue / $inventoryValue, 4) : null,
            'fast_moving_products' => $fastMoving,
            'slow_moving_products' => $slowMoving,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];

        return [
            'key' => ['tenant_id' => $tenantId, 'snapshot_date' => $snapshotDate, 'warehouse_id' => $warehouseId],
            'doc' => $doc,
        ];
    }
}
