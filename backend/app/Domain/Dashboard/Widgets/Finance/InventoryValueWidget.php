<?php

namespace App\Domain\Dashboard\Widgets\Finance;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\DataBasis;
use App\Domain\Dashboard\DashboardPermissions;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * FN-05 Inventory Value — Σ quantity_on_hand × average_unit_cost (the moving-average valuation the inventory
 * already keeps), per warehouse. Current value only: there is no stock-value history, so no trend is shown.
 * Requires dashboard.finance.view.
 *
 * Every balance carries an explicit VALUATION STATUS (warehouse_stocks.valuation_status — never inferred from the
 * cost number). The figure is therefore split, never one number that looks complete:
 *   verified_value     balances VERIFIED (positive cost with a traceable source);
 *   unverified_value   recorded cost whose source is not verified ("status valuasi belum terverifikasi");
 *   mixed_value        balances combining sources of different status — cannot be separated reliably, so flagged;
 *   verified zero      VERIFIED_ZERO balances — a legitimate zero, counted apart;
 *   not valued         used (REUSE) tire stock and NOT_VALUED balances — quantity per UOM only, never a value;
 *   no unit cost       balances with quantity but a cost of 0 and no verified-zero basis — never read as 0 value.
 * `total` keeps its earlier meaning (recorded value of balances with a positive cost, excluding NOT_VALUED) for API
 * compatibility; it is NOT the verified value. Completeness is PARTIAL whenever anything is not verified. Serialized
 * tires and rims are counted once, through the ledger of their product; quantities are never added across UOMs.
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
        return 4; // explicit valuation status: verified / unverified / mixed value, verified zero and not valued reported apart
    }

    /** SQL of the balance's valuation status; a missing status is "unverified" (pre-status rows), never "verified". */
    private const STATUS = "coalesce(ws.valuation_status, 'UNVERIFIED')";

    public function compute(DashboardContext $context): array
    {
        $split = fn (string $status) => "sum(case when ".self::STATUS." = '{$status}' then ws.quantity_on_hand * ws.average_unit_cost else 0 end)";
        $rows = $this->valued($context)
            ->selectRaw("ws.warehouse_id, w.name as warehouse_name, sum(ws.quantity_on_hand * ws.average_unit_cost) as value, count(*) as sku_count,
                {$split('VERIFIED')} as verified_value, {$split('UNVERIFIED')} as unverified_value, {$split('MIXED')} as mixed_value")
            ->groupBy('ws.warehouse_id', 'w.name')
            ->get();
        $warehouses = $rows->map(fn ($r) => [
            'warehouse_id' => $r->warehouse_id, 'warehouse_name' => $r->warehouse_name,
            'value' => self::money($r->value), 'sku_count' => (int) $r->sku_count,
            'verified_value' => self::money($r->verified_value), 'unverified_value' => self::money($r->unverified_value), 'mixed_value' => self::money($r->mixed_value),
        ])->sortByDesc(fn ($r) => (float) $r['value'])->values()->all();
        $valuedSkus = (int) $this->valued($context)->distinct()->count('ws.product_id');
        $byStatus = $this->valued($context)->groupByRaw(self::STATUS)->selectRaw(self::STATUS.' as status, count(*) as balances, sum(ws.quantity_on_hand * ws.average_unit_cost) as value')->get()->keyBy('status');
        $pending = $this->pending($context);
        $zero = $this->verifiedZero($context);
        $count = fn (string $status) => (int) ($byStatus[$status]->balances ?? 0);
        $value = fn (string $status) => self::money($byStatus[$status]->value ?? 0);

        $valuedBalances = $count('VERIFIED') + $count('UNVERIFIED') + $count('MIXED');
        $population = $valuedBalances + $zero['balances'] + $pending['balances'];
        $reasons = ['USED_STOCK_NOT_VALUED' => $pending['used_balances'], 'NO_UNIT_COST' => $pending['no_cost_balances'], 'VALUATION_NOT_PERFORMED' => $pending['not_valued_balances']];
        // Two separate questions: does the balance carry a value at all (completeness), and is that value verified (verification)?
        $verification = DataBasis::completeness($population, $count('VERIFIED') + $zero['balances'],
            ['VALUATION_UNVERIFIED' => $count('UNVERIFIED'), 'VALUATION_MIXED_SOURCES' => $count('MIXED')] + $reasons);
        $limitations = [];
        if ($pending['skus'] > 0) {
            $limitations[] = ['code' => 'dashboard.limitations.valuationPending', 'params' => ['n' => $pending['skus']]];
        }
        if ($count('UNVERIFIED') + $count('MIXED') > 0) {
            $limitations[] = ['code' => 'dashboard.limitations.valuationUnverified', 'params' => ['n' => $count('UNVERIFIED') + $count('MIXED')]];
        }

        return [
            'data' => [
                'total' => self::moneySum($rows->pluck('value')), 'valued_skus' => $valuedSkus, 'warehouses' => $warehouses,
                'verified_value' => $value('VERIFIED'), 'unverified_value' => $value('UNVERIFIED'), 'mixed_value' => $value('MIXED'),
                'status_balances' => ['VERIFIED' => $count('VERIFIED'), 'UNVERIFIED' => $count('UNVERIFIED'), 'MIXED' => $count('MIXED')],
                'verified_zero' => $zero, 'verification' => $verification,
                'item_types' => $this->itemTypes($context), 'in_transit' => $this->inTransit($context), 'pending_valuation' => $pending,
            ],
            'limitations' => $limitations,
            'basis' => DataBasis::make(
                [['key' => 'VALUE', 'code' => 'CURRENT_BALANCE']],
                ['LEDGER_STOCK_WITH_RECORDED_COST', 'VALUATION_STATUS_PER_BALANCE', 'SERIAL_TIRES_AND_RIMS_ONCE_VIA_PRODUCT_LEDGER'],
                ['USED_STOCK_PENDING_VALUATION', 'STOCK_WITHOUT_UNIT_COST', 'IN_TRANSIT', 'NEW_PRICE_FOR_USED_STOCK'],
                DataBasis::completeness($population, $valuedBalances + $zero['balances'], $reasons),
            ),
        ];
    }

    /** Ledger rows with quantity and a recorded unit cost that are valued (a NOT_VALUED review is never valued, whatever the cost). */
    private function valued(DashboardContext $context): Builder
    {
        return $this->stocks($context)->where('ws.quantity_on_hand', '>', 0)->where('ws.average_unit_cost', '>', 0)->whereRaw(self::STATUS." <> 'NOT_VALUED'");
    }

    /**
     * Legitimate zero value: balances reviewed VERIFIED_ZERO. Quantities per UOM, value 0 — counted apart from every other bucket.
     *
     * @return array{skus: int, balances: int, quantities: list<array<string, mixed>>}
     */
    private function verifiedZero(DashboardContext $context): array
    {
        $q = $this->stocks($context)->leftJoin('uoms as u', 'u.id', '=', 'p.uom_id')->where('ws.quantity_on_hand', '>', 0)->whereRaw(self::STATUS." = 'VERIFIED_ZERO'");
        $rows = (clone $q)->groupBy('u.code', 'p.product_type')->selectRaw('p.product_type, u.code as uom, sum(ws.quantity_on_hand) as quantity')->get();

        return ['skus' => (int) (clone $q)->distinct()->count('ws.product_id'), 'balances' => (int) (clone $q)->count(),
            'quantities' => $rows->map(fn ($r) => ['item_type' => $r->product_type, 'uom' => $r->uom, 'quantity' => self::decimal($r->quantity, 4)])->values()->all()];
    }

    /**
     * Stock that cannot carry a value: used tire stock (never valued), NOT_VALUED balances, and balances holding
     * quantity at no unit cost without a verified-zero basis. SKU counts and quantities per UOM (never added across
     * UOMs); no value, and never read as 0.
     *
     * @return array{skus: int, used_skus: int, no_cost_skus: int, not_valued_skus: int, balances: int, used_balances: int, no_cost_balances: int, not_valued_balances: int, quantities: list<array<string, mixed>>}
     */
    private function pending(DashboardContext $context): array
    {
        $used = DB::table('used_tire_stocks as ws')->join('products as p', 'p.id', '=', 'ws.product_id')->leftJoin('uoms as u', 'u.id', '=', 'p.uom_id')
            ->where('ws.tenant_id', $context->tenantId)->where('ws.quantity_on_hand', '>', 0);
        $context->scopeWarehouse($used, 'ws.warehouse_id');
        $noCost = $this->noCost($context);
        $notValued = $this->stocks($context)->leftJoin('uoms as u', 'u.id', '=', 'p.uom_id')->where('ws.quantity_on_hand', '>', 0)->whereRaw(self::STATUS." = 'NOT_VALUED'");

        $quantities = collect();
        foreach (['USED_STOCK' => $used, 'NO_UNIT_COST' => $noCost, 'NOT_VALUED' => $notValued] as $reason => $query) {
            $quantities = $quantities->concat((clone $query)->groupBy('u.code', 'p.product_type')->selectRaw('p.product_type, u.code as uom, sum(ws.quantity_on_hand) as quantity')->get()
                ->map(fn ($r) => ['reason' => $reason, 'item_type' => $r->product_type, 'uom' => $r->uom, 'quantity' => self::decimal($r->quantity, 4)]));
        }
        $skus = fn ($q) => (int) (clone $q)->distinct()->count('ws.product_id');
        $balances = fn ($q) => (int) (clone $q)->count();

        return [
            'skus' => $skus($used) + $skus($noCost) + $skus($notValued), 'used_skus' => $skus($used), 'no_cost_skus' => $skus($noCost), 'not_valued_skus' => $skus($notValued),
            'balances' => $balances($used) + $balances($noCost) + $balances($notValued), 'used_balances' => $balances($used), 'no_cost_balances' => $balances($noCost), 'not_valued_balances' => $balances($notValued),
            'quantities' => $quantities->values()->all(),
        ];
    }

    /** Balances with quantity and no usable unit cost, other than a documented zero / not-valued review. */
    private function noCost(DashboardContext $context): Builder
    {
        return $this->stocks($context)->leftJoin('uoms as u', 'u.id', '=', 'p.uom_id')->where('ws.quantity_on_hand', '>', 0)
            ->where(fn ($q) => $q->whereNull('ws.average_unit_cost')->orWhere('ws.average_unit_cost', '<=', 0))
            ->whereRaw(self::STATUS." not in ('VERIFIED_ZERO', 'NOT_VALUED')");
    }

    /**
     * Per Item Type (products.product_type): distinct products with stock, value, quantities per UOM
     * (never summed across UOMs) and value per warehouse. Serialized tires and rims are counted once,
     * through the stock ledger of their product (the tire / rim registers are not added again).
     */
    private function itemTypes(DashboardContext $context): array
    {
        $st = self::STATUS;
        $cost = 'ws.quantity_on_hand * ws.average_unit_cost';
        $rows = $this->valued($context)
            ->leftJoin('uoms as u', 'u.id', '=', 'p.uom_id')
            ->groupBy('p.product_type', 'u.code', 'ws.warehouse_id', 'w.name')
            ->selectRaw("p.product_type, u.code as uom, ws.warehouse_id, w.name as warehouse_name, count(distinct ws.product_id) as products,
                sum(ws.quantity_on_hand) as quantity, sum({$cost}) as value,
                sum(case when {$st} = 'VERIFIED' then {$cost} else 0 end) as verified_value,
                sum(case when {$st} = 'UNVERIFIED' then {$cost} else 0 end) as unverified_value,
                sum(case when {$st} = 'MIXED' then {$cost} else 0 end) as mixed_value")
            ->get();
        $skus = $this->valued($context)->groupBy('p.product_type')
            ->selectRaw('p.product_type, count(distinct ws.product_id) as c')->pluck('c', 'p.product_type');

        return $rows->groupBy('product_type')->map(fn ($set, $type) => [
            'item_type' => $type,
            'sku_count' => (int) ($skus[$type] ?? 0),
            'value' => self::moneySum($set->pluck('value')),
            'verified_value' => self::moneySum($set->pluck('verified_value')),
            'unverified_value' => self::moneySum($set->pluck('unverified_value')),
            'mixed_value' => self::moneySum($set->pluck('mixed_value')),
            'quantities' => $set->groupBy('uom')->map(fn ($u, $uom) => ['uom' => $uom ?: null, 'quantity' => self::decimal($u->sum('quantity'), 4)])->values()->all(),
            'warehouses' => $set->groupBy('warehouse_id')->map(fn ($w) => ['warehouse_id' => $w->first()->warehouse_id, 'warehouse_name' => $w->first()->warehouse_name,
                'value' => self::moneySum($w->pluck('value')), 'verified_value' => self::moneySum($w->pluck('verified_value'))])->sortByDesc(fn ($w) => (float) $w['value'])->values()->all(),
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

    public function detailRules(): ?array
    {
        return ['warehouse_id' => ['nullable', 'uuid'], 'item_type' => ['nullable', 'string', 'max:40'], 'view' => ['nullable', 'in:pending_valuation'],
            'valuation_status' => ['nullable', 'in:VERIFIED,UNVERIFIED,MIXED']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        if (($params['view'] ?? null) === 'pending_valuation') {
            return $this->pendingDetail($context, $params);
        }
        $query = $this->valued($context)
            ->when($params['warehouse_id'] ?? null, fn ($q, $w) => $q->where('ws.warehouse_id', $w))
            ->when($params['item_type'] ?? null, fn ($q, $type) => $q->where('p.product_type', $type))
            ->when($params['valuation_status'] ?? null, fn ($q, $status) => $q->whereRaw(self::STATUS.' = ?', [$status]))
            ->selectRaw('ws.id, ws.product_id, p.name as product_name, p.sku, p.product_type, w.name as warehouse_name, ws.quantity_on_hand, ws.average_unit_cost,
                (ws.quantity_on_hand * ws.average_unit_cost) as value, '.self::STATUS.' as valuation_status, ws.valuation_basis')
            ->orderByRaw('(ws.quantity_on_hand * ws.average_unit_cost) desc');

        return $this->paginate($query, $params, fn ($r) => [
            'id' => $r->id, 'product_id' => $r->product_id, 'product_name' => $r->product_name, 'sku' => $r->sku, 'item_type' => $r->product_type,
            'warehouse_name' => $r->warehouse_name, 'quantity_on_hand' => self::decimal($r->quantity_on_hand),
            'average_unit_cost' => self::decimal($r->average_unit_cost, 4), 'value' => self::money($r->value),
            'valuation_status' => $r->valuation_status, 'valuation_basis' => $r->valuation_basis,
        ]);
    }

    /**
     * Stock that carries no value, row by row: used tire stock, NOT_VALUED balances and balances at no unit cost, plus
     * verified-zero balances for completeness. Shows the source, quantity, the cost as recorded (never as a value) and
     * why the row is outside the value.
     */
    private function pendingDetail(DashboardContext $context, array $params): array
    {
        $used = DB::table('used_tire_stocks as ws')->join('products as p', 'p.id', '=', 'ws.product_id')->join('warehouses as w', 'w.id', '=', 'ws.warehouse_id')->leftJoin('uoms as u', 'u.id', '=', 'p.uom_id')
            ->where('ws.tenant_id', $context->tenantId)->where('ws.quantity_on_hand', '>', 0);
        $context->scopeWarehouse($used, 'ws.warehouse_id');
        $used->selectRaw("ws.id::text as id, 'USED_STOCK' as reason, 'NOT_VALUED' as valuation_status, null::numeric as average_unit_cost, p.id as product_id, p.name as product_name, p.sku, p.product_type, w.name as warehouse_name, u.code as uom, ws.quantity_on_hand");
        $ledger = fn (string $reason, string $where) => $this->stocks($context)->leftJoin('uoms as u', 'u.id', '=', 'p.uom_id')->where('ws.quantity_on_hand', '>', 0)->whereRaw($where)
            ->selectRaw("ws.id::text as id, '{$reason}' as reason, ".self::STATUS.' as valuation_status, ws.average_unit_cost, p.id as product_id, p.name as product_name, p.sku, p.product_type, w.name as warehouse_name, u.code as uom, ws.quantity_on_hand');
        $noCost = $ledger('NO_UNIT_COST', '(ws.average_unit_cost is null or ws.average_unit_cost <= 0) and '.self::STATUS." not in ('VERIFIED_ZERO', 'NOT_VALUED')");
        $notValued = $ledger('NOT_VALUED', self::STATUS." = 'NOT_VALUED'");
        $zero = $ledger('VERIFIED_ZERO', self::STATUS." = 'VERIFIED_ZERO'");
        $query = DB::query()->fromSub($used->unionAll($noCost)->unionAll($notValued)->unionAll($zero), 'x')
            ->when($params['item_type'] ?? null, fn ($q, $t) => $q->where('x.product_type', $t))->orderBy('x.product_name');

        return $this->paginate($query, $params, fn ($r) => [
            'id' => $r->id, 'reason' => $r->reason, 'valuation_status' => $r->valuation_status, 'product_id' => $r->product_id, 'product_name' => $r->product_name, 'sku' => $r->sku, 'item_type' => $r->product_type,
            'warehouse_name' => $r->warehouse_name, 'uom' => $r->uom, 'quantity_on_hand' => self::decimal($r->quantity_on_hand),
            'average_unit_cost' => $r->average_unit_cost === null ? null : self::decimal($r->average_unit_cost, 4),
            'value' => $r->reason === 'VERIFIED_ZERO' ? self::money(0) : null,
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
