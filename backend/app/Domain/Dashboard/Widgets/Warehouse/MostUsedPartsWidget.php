<?php

namespace App\Domain\Dashboard\Widgets\Warehouse;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\Widgets\Widget;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * WH-06 Most Used Spareparts & Tires — products ranked by Used Times = number of distinct Work
 * Orders that consumed them in the period (owner decision: splitting a consumption into several
 * entries never inflates it). Consumption events:
 *  - every CONSUME ledger entry of a Work Order part line (occurred_at);
 *  - reused (USED-condition) tires, which have no ledger entry: the installation, by that Work Order,
 *    of each tire issued from used stock for the part line (tire_installations.installed_at).
 * Quantities are summed per product only (one UOM each), never across products; planned, requested
 * or issued quantities are never used. Scope: the consuming warehouse (warehouse scope).
 */
class MostUsedPartsWidget extends Widget
{
    public const TOP = 10;

    public function id(): string
    {
        return 'WH-06';
    }

    public function kind(): string
    {
        return self::KIND_PERIOD;
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

    public function paramRules(): array
    {
        return ['item_type' => ['nullable', 'string', 'max:40'], 'product_category_id' => ['nullable', 'uuid']];
    }

    public function compute(DashboardContext $context): array
    {
        $all = $this->ranking($context)->get();
        $options = DB::query()->fromSub($this->events($context, false), 'e')->join('products as p', 'p.id', '=', 'e.product_id')
            ->leftJoin('product_categories as c', 'c.id', '=', 'p.product_category_id')
            ->selectRaw('distinct p.product_type, p.product_category_id, c.name as category_name')->get();

        return ['data' => [
            'products' => $all->take(self::TOP)->map(fn ($r) => $this->present($r))->values()->all(),
            'products_total' => $all->count(),
            'events' => (int) $all->sum('events'),
            'options' => [
                'item_types' => $options->pluck('product_type')->filter()->unique()->sort()->values()->all(),
                'categories' => $options->whereNotNull('product_category_id')->unique('product_category_id')->sortBy('category_name')
                    ->map(fn ($r) => ['id' => $r->product_category_id, 'name' => $r->category_name])->values()->all(),
            ],
        ]];
    }

    public function detailRules(): ?array
    {
        return ['product_id' => ['nullable', 'uuid']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        if (empty($params['product_id'])) {
            return $this->paginate($this->ranking($context), $params, fn ($r) => $this->present($r));
        }
        $query = DB::query()->fromSub($this->events($context), 'e')->where('e.product_id', $params['product_id'])
            ->join('work_orders as wo', 'wo.id', '=', 'e.work_order_id')
            ->leftJoin('vehicles as v', 'v.id', '=', 'wo.vehicle_id')
            ->leftJoin('warehouses as w', 'w.id', '=', 'e.warehouse_id')
            ->orderByDesc('e.at')
            ->select(['e.id', 'e.at', 'e.quantity', 'e.source', 'wo.id as work_order_id', 'wo.wo_number', 'v.id as vehicle_id', 'v.registration_number', 'w.name as warehouse_name']);

        return $this->paginate($query, $params, fn ($r) => [
            'id' => $r->id, 'consumed_at' => self::isoUtc($r->at), 'quantity' => self::decimal($r->quantity, 4), 'source' => $r->source,
            'work_order_id' => $r->work_order_id, 'wo_number' => $r->wo_number, 'vehicle_id' => $r->vehicle_id,
            'registration_number' => $r->registration_number, 'warehouse_name' => $r->warehouse_name,
        ]);
    }

    private function ranking(DashboardContext $context): Builder
    {
        return DB::query()->fromSub($this->events($context), 'e')
            ->join('products as p', 'p.id', '=', 'e.product_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'p.uom_id')
            ->join('work_orders as wo', 'wo.id', '=', 'e.work_order_id')
            ->groupBy('p.id', 'p.sku', 'p.name', 'p.product_type', 'u.code')
            ->selectRaw('p.id as product_id, p.sku, p.name as product_name, p.product_type, u.code as uom,
                count(distinct e.work_order_id) as used_times, count(*) as events, sum(e.quantity) as quantity,
                count(distinct wo.vehicle_id) as vehicles')
            ->orderByDesc('used_times')->orderByDesc('quantity')->orderBy('p.name');
    }

    /** One row per consumption event in the period (scope + filters). */
    private function events(DashboardContext $context, bool $withParams = true): Builder
    {
        [$fromUtc, $toUtc] = $context->utcBounds($context->periodStartDate(), $context->periodEndDateExclusive());
        $narrow = function (Builder $q) use ($context, $withParams) {
            $q->where('pp.tenant_id', $context->tenantId);
            $context->scopeWarehouse($q, 'pp.warehouse_id');
            if ($withParams) {
                $q->when($context->filters->param('item_type'), fn ($q, $t) => $q->whereIn('pp.product_id', DB::table('products')->where('product_type', $t)->select('id')))
                    ->when($context->filters->param('product_category_id'), fn ($q, $c) => $q->whereIn('pp.product_id', DB::table('products')->where('product_category_id', $c)->select('id')));
            }

            return $q;
        };

        $ledger = $narrow(DB::table('stock_movements as sm')
            ->join('work_order_planned_parts as pp', function ($j) {
                $j->on('pp.id', '=', 'sm.reference_id')->where('sm.reference_type', WorkOrderPlannedPart::class);
            })
            ->where('sm.movement_type', 'CONSUME')->where('sm.occurred_at', '>=', $fromUtc)->where('sm.occurred_at', '<', $toUtc)
            ->selectRaw("sm.id::text as id, pp.product_id, pp.work_order_id, pp.warehouse_id, sm.quantity, sm.occurred_at as at, 'LEDGER' as source"));

        // Exactly the tires issued from used stock for that part line, installed by its Work Order.
        // (exists, not join: a tire issued twice for the same line is still one installation event).
        $reused = $narrow(DB::table('work_order_planned_parts as pp')
            ->join('tire_installations as ti', 'ti.work_order_id', '=', 'pp.work_order_id')
            ->whereExists(fn ($q) => $q->from('used_tire_stock_movements as um')->whereColumn('um.reference_id', 'pp.id')
                ->whereColumn('um.tire_id', 'ti.tire_id')->where('um.reference_type', WorkOrderPlannedPart::class)->where('um.movement_type', 'ISSUE'))
            ->where('pp.stock_condition', 'USED')->where('pp.consumed_quantity', '>', 0)
            ->where('ti.installed_at', '>=', $fromUtc)->where('ti.installed_at', '<', $toUtc)
            ->selectRaw("ti.id::text as id, pp.product_id, pp.work_order_id, pp.warehouse_id, 1 as quantity, ti.installed_at as at, 'REUSED_TIRE' as source"));

        return $ledger->unionAll($reused);
    }

    private function present(object $r): array
    {
        return [
            'product_id' => $r->product_id, 'sku' => $r->sku, 'product_name' => $r->product_name, 'item_type' => $r->product_type,
            'uom' => $r->uom, 'used_times' => (int) $r->used_times, 'work_orders' => (int) $r->used_times, 'events' => (int) $r->events,
            'quantity' => self::decimal($r->quantity, 4), 'vehicles' => (int) $r->vehicles,
        ];
    }
}
