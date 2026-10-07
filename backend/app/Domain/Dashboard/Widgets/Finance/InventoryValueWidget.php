<?php

namespace App\Domain\Dashboard\Widgets\Finance;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\DashboardPermissions;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * FN-05 Inventory Value — Σ quantity_on_hand × average_unit_cost (the moving-average valuation the
 * inventory already keeps), per warehouse. Current value only: there is no stock-value history, so no
 * trend is shown. Requires dashboard.finance.view.
 */
class InventoryValueWidget extends Widget
{
    public function id(): string
    {
        return 'FN-05';
    }

    public function unit(): string
    {
        return 'money';
    }

    public function modules(): array
    {
        return ['INVENTORY'];
    }

    public function permissions(): array
    {
        return [DashboardPermissions::FINANCE];
    }

    public function filters(): array
    {
        return ['branch', 'warehouse'];
    }

    public function compute(DashboardContext $context): array
    {
        $rows = $this->stocks($context)
            ->selectRaw('ws.warehouse_id, w.name as warehouse_name, sum(ws.quantity_on_hand * ws.average_unit_cost) as value, count(*) filter (where ws.quantity_on_hand > 0) as sku_count')
            ->groupBy('ws.warehouse_id', 'w.name')
            ->get();
        $warehouses = $rows->map(fn ($r) => [
            'warehouse_id' => $r->warehouse_id, 'warehouse_name' => $r->warehouse_name,
            'value' => self::money($r->value), 'sku_count' => (int) $r->sku_count,
        ])->sortByDesc(fn ($r) => (float) $r['value'])->values()->all();

        return ['data' => ['total' => self::moneySum($rows->pluck('value')), 'warehouses' => $warehouses]];
    }

    public function detailRules(): ?array
    {
        return ['warehouse_id' => ['nullable', 'uuid']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $query = $this->stocks($context)->where('ws.quantity_on_hand', '>', 0)
            ->when($params['warehouse_id'] ?? null, fn ($q, $w) => $q->where('ws.warehouse_id', $w))
            ->selectRaw('ws.id, ws.product_id, p.name as product_name, p.sku, w.name as warehouse_name, ws.quantity_on_hand, ws.average_unit_cost,
                (ws.quantity_on_hand * ws.average_unit_cost) as value')
            ->orderByRaw('(ws.quantity_on_hand * ws.average_unit_cost) desc');

        return $this->paginate($query, $params, fn ($r) => [
            'id' => $r->id, 'product_id' => $r->product_id, 'product_name' => $r->product_name, 'sku' => $r->sku,
            'warehouse_name' => $r->warehouse_name, 'quantity_on_hand' => self::decimal($r->quantity_on_hand),
            'average_unit_cost' => self::decimal($r->average_unit_cost, 4), 'value' => self::money($r->value),
        ]);
    }

    private function stocks(DashboardContext $context): Builder
    {
        $query = DB::table('warehouse_stocks as ws')
            ->join('products as p', 'p.id', '=', 'ws.product_id')
            ->join('warehouses as w', 'w.id', '=', 'ws.warehouse_id')
            ->where('ws.tenant_id', $context->tenantId)->whereNull('w.deleted_at');

        return $context->scopeWarehouse($query, 'ws.warehouse_id');
    }
}
