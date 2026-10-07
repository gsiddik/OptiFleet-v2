<?php

namespace App\Domain\Dashboard\Widgets\Warehouse;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * WH-01 Stock Health — product × warehouse stock rows by quantity on hand compared with the reorder
 * point (owner decision: on hand; the retired Inventory Reservation feature is not revived):
 *  OUT  on hand ≤ 0 · LOW  0 < on hand ≤ reorder point · NOT_SET  in stock, no reorder point
 *  (never treated as 0) · NORMAL  above its reorder point.
 */
class StockHealthWidget extends Widget
{
    public function id(): string
    {
        return 'WH-01';
    }

    public function modules(): array
    {
        return ['INVENTORY'];
    }

    public function permissions(): array
    {
        return ['inventory.view'];
    }

    public function filters(): array
    {
        return ['branch', 'warehouse'];
    }

    public function compute(DashboardContext $context): array
    {
        $row = $this->stocks($context)->selectRaw(
            'count(*) filter (where '.self::OUT.') as out_count,
             count(*) filter (where '.self::LOW.') as low_count,
             count(*) filter (where not ('.self::OUT.') and ws.reorder_point is null) as not_set_count,
             count(*) filter (where not ('.self::OUT.') and ws.reorder_point is not null and not ('.self::LOW.')) as normal_count'
        )->first();

        return ['data' => [
            'total' => (int) $row->out_count + (int) $row->low_count + (int) $row->not_set_count + (int) $row->normal_count,
            'by_state' => ['OUT' => (int) $row->out_count, 'LOW' => (int) $row->low_count, 'NORMAL' => (int) $row->normal_count, 'NOT_SET' => (int) $row->not_set_count],
        ]];
    }

    public function version(): int
    {
        return 3; // on-hand basis, NOT_SET state (nullable reorder point)
    }

    public const AVAILABLE = 'ws.quantity_on_hand';

    public const OUT = 'ws.quantity_on_hand <= 0';

    public const LOW = 'ws.quantity_on_hand > 0 and ws.quantity_on_hand <= ws.reorder_point';

    protected function stocks(DashboardContext $context): Builder
    {
        $query = DB::table('warehouse_stocks as ws')
            ->join('products as p', 'p.id', '=', 'ws.product_id')
            ->join('warehouses as w', 'w.id', '=', 'ws.warehouse_id')
            ->where('ws.tenant_id', $context->tenantId)->whereNull('p.deleted_at')->whereNull('w.deleted_at');

        return $context->scopeWarehouse($query, 'ws.warehouse_id');
    }
}
