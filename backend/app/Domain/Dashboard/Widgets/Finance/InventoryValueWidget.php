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

    public function version(): int
    {
        return 2; // item-type breakdown, used / in-transit stock reported separately
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

        return [
            'data' => ['total' => self::moneySum($rows->pluck('value')), 'warehouses' => $warehouses, 'item_types' => $this->itemTypes($context), 'in_transit' => $this->inTransit($context)],
            'limitations' => $this->limitations($context),
        ];
    }

    /**
     * Per Item Type (products.product_type): distinct products with stock, value, quantities per UOM
     * (never summed across UOMs) and value per warehouse. Serialized tires and rims are counted once,
     * through the stock ledger of their product (the tire / rim registers are not added again).
     */
    private function itemTypes(DashboardContext $context): array
    {
        $rows = $this->stocks($context)->where('ws.quantity_on_hand', '>', 0)
            ->leftJoin('uoms as u', 'u.id', '=', 'p.uom_id')
            ->groupBy('p.product_type', 'u.code', 'ws.warehouse_id', 'w.name')
            ->selectRaw('p.product_type, u.code as uom, ws.warehouse_id, w.name as warehouse_name, count(distinct ws.product_id) as products,
                sum(ws.quantity_on_hand) as quantity, sum(ws.quantity_on_hand * ws.average_unit_cost) as value')
            ->get();
        $skus = $this->stocks($context)->where('ws.quantity_on_hand', '>', 0)->groupBy('p.product_type')
            ->selectRaw('p.product_type, count(distinct ws.product_id) as c')->pluck('c', 'p.product_type');

        return $rows->groupBy('product_type')->map(fn ($set, $type) => [
            'item_type' => $type,
            'sku_count' => (int) ($skus[$type] ?? 0),
            'value' => self::moneySum($set->pluck('value')),
            'quantities' => $set->groupBy('uom')->map(fn ($u, $uom) => ['uom' => $uom ?: null, 'quantity' => self::decimal($u->sum('quantity'), 4)])->values()->all(),
            'warehouses' => $set->groupBy('warehouse_id')->map(fn ($w) => ['warehouse_id' => $w->first()->warehouse_id, 'warehouse_name' => $w->first()->warehouse_name,
                'value' => self::moneySum($w->pluck('value'))])->sortByDesc(fn ($w) => (float) $w['value'])->values()->all(),
        ])->sortByDesc(fn ($r) => (float) $r['value'])->values()->all();
    }

    /** Dispatched, not yet received transfer lines: in no warehouse ledger, so outside the total. */
    private function inTransit(DashboardContext $context): array
    {
        $query = DB::table('stock_transfer_items as i')->join('stock_transfers as t', 't.id', '=', 'i.stock_transfer_id')
            ->where('t.tenant_id', $context->tenantId)->where('t.status', 'IN_TRANSIT');
        $query->where(fn ($q) => $context->scopeWarehouse($q, 't.from_warehouse_id')->orWhere(fn ($q2) => $context->scopeWarehouse($q2, 't.to_warehouse_id')));
        $row = $query->selectRaw('count(distinct t.id) as transfers, coalesce(sum(i.quantity_sent * coalesce(i.unit_cost, 0)), 0) as value')->first();

        return ['transfers' => (int) $row->transfers, 'value' => self::money($row->value)];
    }

    /** Stock that has no valuation in the inventory (used tires) is reported, never valued at 0 or at new price. */
    private function limitations(DashboardContext $context): array
    {
        $used = DB::table('used_tire_stocks as ws')->where('ws.tenant_id', $context->tenantId)->where('ws.quantity_on_hand', '>', 0);
        $context->scopeWarehouse($used, 'ws.warehouse_id');
        $quantity = (int) $used->sum('ws.quantity_on_hand');

        return $quantity > 0 ? [['code' => 'dashboard.limitations.usedStockNotValued', 'params' => ['n' => $quantity]]] : [];
    }

    public function detailRules(): ?array
    {
        return ['warehouse_id' => ['nullable', 'uuid'], 'item_type' => ['nullable', 'string', 'max:40']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $query = $this->stocks($context)->where('ws.quantity_on_hand', '>', 0)
            ->when($params['warehouse_id'] ?? null, fn ($q, $w) => $q->where('ws.warehouse_id', $w))
            ->when($params['item_type'] ?? null, fn ($q, $type) => $q->where('p.product_type', $type))
            ->selectRaw('ws.id, ws.product_id, p.name as product_name, p.sku, p.product_type, w.name as warehouse_name, ws.quantity_on_hand, ws.average_unit_cost,
                (ws.quantity_on_hand * ws.average_unit_cost) as value')
            ->orderByRaw('(ws.quantity_on_hand * ws.average_unit_cost) desc');

        return $this->paginate($query, $params, fn ($r) => [
            'id' => $r->id, 'product_id' => $r->product_id, 'product_name' => $r->product_name, 'sku' => $r->sku, 'item_type' => $r->product_type,
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
