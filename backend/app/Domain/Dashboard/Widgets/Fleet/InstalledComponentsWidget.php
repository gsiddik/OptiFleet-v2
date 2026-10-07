<?php

namespace App\Domain\Dashboard\Widgets\Fleet;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\DataBasis;
use App\Domain\Dashboard\Widgets\Widget;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * FL-07 Installed Components — what is installed on each vehicle NOW, with its installation cost
 * (owner decision 7; a cost snapshot, not a book / net asset value):
 *  - tires (tire_installations not removed): the issue-time unit cost of the installing Work Order's
 *    NEW part line for that tire product (Σ total_cost / Σ issued of those lines, 4 dp). A reused
 *    tire has no valuation in the inventory; a tire registered without a Work Order has no cost
 *    basis — both are listed with "cost not available", never 0;
 *  - rims and serialized components (component_installations not removed): the component asset's
 *    Goods Receipt purchase cost, else "cost not available".
 * Non-serialized spare parts have no installation / removal record and are not shown (consumed is
 * not installed). A component moved to another vehicle has one open installation only, so it is
 * never counted twice. Current position only — there is no installed-value history.
 * Values are returned only with dashboard.finance.view.
 *
 * Coverage is explicit: only units with an installation record are "installed". Non-serial spare parts and
 * consumables that Work Orders consumed have NO installation / removal record, so they are reported as
 * "untracked" (lines / products / quantity per UOM, no value) — never as 0 installed and never assumed to
 * still be on the vehicle. Each installed item carries the cost basis of its snapshot (or why it has none).
 */
class InstalledComponentsWidget extends Widget
{
    public const TOP = 10;

    public function id(): string
    {
        return 'FL-07';
    }

    public function unit(): string
    {
        return 'mixed';
    }

    public function modules(): array
    {
        return ['VEHICLE'];
    }

    public function permissions(): array
    {
        return ['vehicle.view'];
    }

    public function version(): int
    {
        return 2; // coverage (untracked non-serial parts) and completeness basis
    }

    public function isAvailable(DashboardContext $context): bool
    {
        return parent::isAvailable($context) && ($this->tires($context) || $this->components($context));
    }

    public function compute(DashboardContext $context): array
    {
        $items = $this->items($context);
        $finance = $context->canFinance();
        $value = fn (Collection $set) => $this->valueOf($set, $finance);

        $vehicles = $items->groupBy('vehicle_id')->map(fn (Collection $set) => [
            'vehicle_id' => $set->first()->vehicle_id, 'registration_number' => $set->first()->registration_number,
            'items' => $set->count(), 'unvalued' => $set->whereNull('cost')->count(), 'value' => $value($set),
        ])->sortByDesc(fn ($v) => $finance ? (float) $v['value'] : $v['items'])->values();

        return [
            'data' => [
                'values_visible' => $finance,
                'totals' => ['items' => $items->count(), 'unvalued' => $items->whereNull('cost')->count(), 'value' => $value($items), 'vehicles' => $vehicles->count()],
                'by_type' => $items->groupBy('item_type')->map(fn ($set, $type) => ['item_type' => $type, 'items' => $set->count(), 'unvalued' => $set->whereNull('cost')->count(), 'value' => $value($set)])
                    ->sortByDesc('items')->values()->all(),
                'vehicles' => $vehicles->take(self::TOP)->all(),
                'sources' => array_keys(array_filter(['TIRE' => $this->tires($context), 'COMPONENT' => $this->components($context)])),
                'untracked' => $this->untracked($context),
            ],
            'limitations' => [['code' => 'dashboard.limitations.nonSerializedNotTracked', 'params' => []]],
            'basis' => DataBasis::make(
                [['key' => 'INSTALLED', 'code' => 'INSTALLATION_DATE'], ['key' => 'COST', 'code' => 'COST_SNAPSHOT_AT_ISSUE_OR_PURCHASE']],
                ['SERIAL_TIRES_OPEN_INSTALLATIONS', 'SERIAL_RIMS_AND_COMPONENTS_OPEN_INSTALLATIONS'],
                ['NON_SERIAL_PARTS_CONSUMED', 'CONSUMABLES', 'REMOVED_OR_MOVED_UNITS', 'BOOK_VALUE'],
                DataBasis::completeness($items->count(), $items->whereNotNull('cost')->count(), [
                    'REUSED_NO_VALUATION' => $items->where('basis', 'REUSED_NO_VALUATION')->count(),
                    'NO_COST_BASIS' => $items->where('basis', 'NO_COST_BASIS')->count(),
                ]),
            ),
        ];
    }

    public function detailRules(): ?array
    {
        return ['vehicle_id' => ['nullable', 'uuid']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $items = $this->items($context);
        $finance = $context->canFinance();
        if (! empty($params['vehicle_id'])) {
            $rows = $items->where('vehicle_id', $params['vehicle_id'])->sortBy([['item_type', 'asc'], ['position', 'asc']])->values()
                ->map(fn ($i) => [
                    'id' => $i->id, 'item_type' => $i->item_type, 'product_name' => $i->product_name, 'sku' => $i->sku, 'serial_number' => $i->serial_number,
                    'position' => $i->position, 'installed_at' => self::isoUtc($i->installed_at), 'work_order_id' => $i->work_order_id, 'wo_number' => $i->wo_number,
                    'cost' => $finance ? $i->cost : null, 'cost_basis' => $i->basis, 'tire_id' => $i->tire_id, 'asset_id' => $i->asset_id,
                ])->all();

            return $this->paginateList($rows, $params);
        }
        $vehicles = $items->groupBy('vehicle_id')->map(fn (Collection $set) => [
            'vehicle_id' => $set->first()->vehicle_id, 'registration_number' => $set->first()->registration_number,
            'items' => $set->count(), 'unvalued' => $set->whereNull('cost')->count(),
            'value' => $this->valueOf($set, $finance),
        ])->sortByDesc(fn ($v) => $finance ? (float) $v['value'] : $v['items'])->values()->all();

        return $this->paginateList($vehicles, $params);
    }

    /**
     * Non-serial parts consumed on Work Orders of vehicles in scope: no installation record exists, so they are
     * neither counted as installed nor valued. Lines / products and quantity per UOM only.
     *
     * @return array{lines: int, products: int, quantities: list<array{uom: ?string, quantity: string}>}
     */
    private function untracked(DashboardContext $context): array
    {
        $vehicles = DB::table('vehicles as v')->where('v.tenant_id', $context->tenantId)->whereNull('v.deleted_at');
        $context->scopeBranch($vehicles, 'v.branch_id');
        $rows = DB::table('work_order_planned_parts as pp')->join('work_orders as wo', 'wo.id', '=', 'pp.work_order_id')->join('products as p', 'p.id', '=', 'pp.product_id')
            ->leftJoin('uoms as u', 'u.id', '=', 'p.uom_id')
            ->where('pp.tenant_id', $context->tenantId)->whereNull('wo.deleted_at')->whereIn('wo.vehicle_id', $vehicles->select('v.id'))
            ->where('pp.consumed_quantity', '>', 0)->where('p.product_type', '!=', 'TIRE')->where(fn ($q) => $q->where('p.track_serial_number', false)->orWhereNull('p.track_serial_number'))
            ->groupBy('u.code')->selectRaw('u.code as uom, count(*) as lines, count(distinct pp.product_id) as products, sum(pp.consumed_quantity) as quantity')->get();

        return [
            'lines' => (int) $rows->sum('lines'), 'products' => (int) $rows->sum('products'),
            'quantities' => $rows->map(fn ($r) => ['uom' => $r->uom, 'quantity' => self::decimal($r->quantity, 4)])->values()->all(),
        ];
    }

    /** Sum of the recorded costs of a set; null (unavailable, never 0) when no item has a cost basis or the user may not see values. */
    private function valueOf(Collection $set, bool $finance): ?string
    {
        $costs = $set->pluck('cost')->filter(fn ($c) => $c !== null);

        return $finance && $costs->isNotEmpty() ? self::moneySum($costs) : null;
    }

    private function tires(DashboardContext $context): bool
    {
        return $context->hasModule('TIRE') && $context->can('tire.view');
    }

    private function components(DashboardContext $context): bool
    {
        return $context->hasModule('INVENTORY') && ($context->can('component_asset.view') || $context->can('inventory.view'));
    }

    /** Installed items (open installations) on vehicles in the user's branch scope. */
    private function items(DashboardContext $context): Collection
    {
        $vehicles = DB::table('vehicles as v')->where('v.tenant_id', $context->tenantId)->whereNull('v.deleted_at');
        $context->scopeBranch($vehicles, 'v.branch_id');
        $vehicleIds = $vehicles->select('v.id');
        $out = collect();

        if ($this->tires($context)) {
            $newCost = DB::table('work_order_planned_parts as pp')->whereColumn('pp.work_order_id', 'ti.work_order_id')->whereColumn('pp.product_id', 't.product_id')
                ->where('pp.stock_condition', 'NEW')->where('pp.issued_quantity', '>', 0)->whereNotNull('pp.total_cost')
                ->selectRaw('round(sum(pp.total_cost) / sum(pp.issued_quantity), 4)');
            $reused = DB::table('used_tire_stock_movements as um')->join('work_order_planned_parts as upp', 'upp.id', '=', 'um.reference_id')
                ->whereColumn('um.tire_id', 'ti.tire_id')->whereColumn('upp.work_order_id', 'ti.work_order_id')
                ->where('um.reference_type', WorkOrderPlannedPart::class)->where('um.movement_type', 'ISSUE')->selectRaw('1');
            $out = $out->concat(DB::table('tire_installations as ti')
                ->join('tires as t', 't.id', '=', 'ti.tire_id')->join('vehicles as v', 'v.id', '=', 'ti.vehicle_id')
                ->leftJoin('products as p', 'p.id', '=', 't.product_id')->leftJoin('work_orders as wo', 'wo.id', '=', 'ti.work_order_id')
                ->where('ti.tenant_id', $context->tenantId)->whereNull('ti.removed_at')->whereNull('t.deleted_at')->whereIn('ti.vehicle_id', $vehicleIds)
                ->selectRaw("ti.id, ti.vehicle_id, v.registration_number, 'TIRE' as item_type, p.name as product_name, p.sku, t.serial_number,
                    ti.wheel_position as position, ti.installed_at, ti.work_order_id, wo.wo_number, t.id as tire_id, null::uuid as asset_id")
                ->selectSub($newCost, 'unit_cost')->selectRaw('exists ('.$reused->toSql().') as reused', $reused->getBindings())
                ->get()->map(function ($r) {
                    [$r->cost, $r->basis] = match (true) {
                        (bool) $r->reused => [null, 'REUSED_NO_VALUATION'],
                        $r->unit_cost !== null => [(string) BigDecimal::of((string) $r->unit_cost)->toScale(2, RoundingMode::HALF_UP), 'WORK_ORDER_CONSUMPTION'],
                        default => [null, 'NO_COST_BASIS'],
                    };

                    return $r;
                }));
        }

        if ($this->components($context)) {
            $out = $out->concat(DB::table('component_installations as ci')
                ->join('component_assets as ca', 'ca.id', '=', 'ci.component_asset_id')->join('vehicles as v', 'v.id', '=', 'ci.vehicle_id')
                ->leftJoin('products as p', 'p.id', '=', 'ca.product_id')->leftJoin('work_orders as wo', 'wo.id', '=', 'ci.work_order_id')
                ->where('ci.tenant_id', $context->tenantId)->whereNull('ci.removed_at')->whereNull('ca.deleted_at')->whereIn('ci.vehicle_id', $vehicleIds)
                ->get(['ci.id', 'ci.vehicle_id', 'v.registration_number', DB::raw("coalesce(p.product_type, 'COMPONENT') as item_type"), 'p.name as product_name', 'p.sku',
                    'ca.serial_number', 'ci.position_location as position', 'ci.installed_at', 'ci.work_order_id', 'wo.wo_number',
                    DB::raw('null::uuid as tire_id'), 'ca.id as asset_id', 'ca.purchase_cost'])
                ->map(function ($r) {
                    $r->cost = $r->purchase_cost === null ? null : (string) BigDecimal::of((string) $r->purchase_cost)->toScale(2, RoundingMode::HALF_UP);
                    $r->basis = $r->purchase_cost === null ? 'NO_COST_BASIS' : 'PURCHASE_COST';

                    return $r;
                }));
        }

        return $out->values();
    }
}
