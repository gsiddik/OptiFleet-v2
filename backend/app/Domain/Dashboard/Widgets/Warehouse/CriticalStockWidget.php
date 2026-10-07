<?php

namespace App\Domain\Dashboard\Widgets\Warehouse;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\Widgets\Workshop\WorkOrderWidget;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * WH-02 Low Stock — stock rows below their threshold (the existing Warehouse Stock reorder point,
 * compared with quantity on hand — owner decision), lowest stock first, quantities only compared
 * within one UOM (in-card UOM filter; ratio to threshold as supporting information):
 *  OUT      on hand ≤ 0 (with or without a threshold);
 *  LOW      0 < on hand ≤ reorder point;
 *  NOT_SET  in stock but no reorder point configured — listed separately, never assumed 0 (an
 *           intentional 0 is a set threshold).
 * Rows flagged when an open Work Order still needs the product from that warehouse. The threshold
 * is changed through the existing Warehouse Stock threshold endpoint (inventory.adjust).
 */
class CriticalStockWidget extends StockHealthWidget
{
    public const STATES = ['OUT', 'LOW', 'NOT_SET'];

    public function id(): string
    {
        return 'WH-02';
    }

    public function version(): int
    {
        return 3;
    }

    public function paramRules(): array
    {
        return ['uom' => ['nullable', 'string', 'max:40'], 'item_type' => ['nullable', 'string', 'max:40']];
    }

    public function compute(DashboardContext $context): array
    {
        $counts = $this->filtered($context)->selectRaw(
            'count(*) filter (where '.self::OUT.') as out_count, count(*) filter (where '.self::LOW.') as low_count,
             count(*) filter (where not ('.self::OUT.') and ws.reorder_point is null) as not_set_count,
             count(*) filter (where (('.self::OUT.') or ('.self::LOW.')) and '.$this->neededSql($context).') as needed_count'
        )->first();
        $items = $this->rows($context, ['OUT', 'LOW'])->limit(10)->get()->map(fn ($r) => $this->present($r))->all();
        $options = $this->stocks($context)->whereRaw('(('.self::OUT.') or ('.self::LOW.') or ws.reorder_point is null)')
            ->leftJoin('uoms as u', 'u.id', '=', 'p.uom_id')
            ->selectRaw('distinct u.code as uom, p.product_type')->get();

        return ['data' => [
            'count' => (int) $counts->out_count + (int) $counts->low_count,
            'by_state' => ['OUT' => (int) $counts->out_count, 'LOW' => (int) $counts->low_count, 'NOT_SET' => (int) $counts->not_set_count],
            'needed_by_work_orders' => (int) $counts->needed_count,
            'items' => $items,
            'can_manage_threshold' => $context->can('inventory.adjust'),
            'options' => [
                'uoms' => $options->pluck('uom')->filter()->unique()->sort()->values()->all(),
                'item_types' => $options->pluck('product_type')->filter()->unique()->sort()->values()->all(),
            ],
        ]];
    }

    public function detailRules(): ?array
    {
        return ['state' => ['nullable', Rule::in(self::STATES)], 'needed' => ['nullable', 'boolean']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $states = isset($params['state']) ? [$params['state']] : ['OUT', 'LOW'];
        $query = $this->rows($context, $states)->when(! empty($params['needed']), fn ($q) => $q->whereRaw($this->neededSql($context)));

        return $this->paginate($query, $params, fn ($r) => $this->present($r));
    }

    /** Stock rows in scope narrowed by the in-card UOM / item type filters. */
    private function filtered(DashboardContext $context): Builder
    {
        return $this->stocks($context)
            ->when($context->filters->param('item_type'), fn ($q, $type) => $q->where('p.product_type', $type))
            ->when($context->filters->param('uom'), fn ($q, $uom) => $q->whereIn('p.uom_id',
                DB::table('uoms')->where('code', $uom)->where(fn ($w) => $w->whereNull('tenant_id')->orWhere('tenant_id', $context->tenantId))->select('id')));
    }

    /** True when an open Work Order of this tenant still needs the product from this warehouse. */
    private function neededSql(DashboardContext $context): string
    {
        $statuses = "'".implode("','", WorkOrderWidget::OPEN_STATUSES)."'";
        $tenant = DB::getPdo()->quote($context->tenantId);

        return "exists (select 1 from work_order_planned_parts pp join work_orders pwo on pwo.id = pp.work_order_id
            where pp.product_id = ws.product_id and pp.warehouse_id = ws.warehouse_id and pwo.tenant_id = {$tenant}
            and pwo.deleted_at is null and pwo.status in ({$statuses})
            and coalesce(pp.planned_quantity, pp.quantity, 0) - coalesce(pp.issued_quantity, 0) > 0)";
    }

    private function rows(DashboardContext $context, array $states): Builder
    {
        $conditions = array_map(fn ($s) => match ($s) {
            'OUT' => '('.self::OUT.')',
            'LOW' => '('.self::LOW.')',
            'NOT_SET' => '(not ('.self::OUT.') and ws.reorder_point is null)',
        }, $states);

        return $this->filtered($context)->leftJoin('uoms as u', 'u.id', '=', 'p.uom_id')
            ->whereRaw('('.implode(' or ', $conditions).')')
            ->selectRaw('ws.id, ws.product_id, p.name as product_name, p.sku, p.product_type, u.code as uom, ws.warehouse_id, w.name as warehouse_name,
                ws.quantity_on_hand, ws.reorder_point, ('.self::OUT.') as is_out, '.$this->neededSql($context).' as needed')
            ->orderBy('u.code')->orderBy('ws.quantity_on_hand')
            ->orderByRaw('case when ws.reorder_point > 0 then ws.quantity_on_hand / ws.reorder_point end asc nulls last')
            ->orderBy('p.name');
    }

    private function present(object $r): array
    {
        $onHand = BigDecimal::of((string) $r->quantity_on_hand);
        $threshold = $r->reorder_point === null ? null : BigDecimal::of((string) $r->reorder_point);
        $state = $r->is_out ? 'OUT' : ($threshold === null ? 'NOT_SET' : 'LOW');

        return [
            'id' => $r->id, 'product_id' => $r->product_id, 'product_name' => $r->product_name, 'sku' => $r->sku,
            'item_type' => $r->product_type, 'uom' => $r->uom, 'warehouse_id' => $r->warehouse_id, 'warehouse_name' => $r->warehouse_name,
            'on_hand' => self::decimal($r->quantity_on_hand),
            'reorder_point' => $threshold === null ? null : self::decimal($r->reorder_point),
            'shortage' => $threshold === null ? null : self::decimal((string) BigDecimal::max($threshold->minus($onHand), BigDecimal::zero())),
            'ratio' => $threshold !== null && $threshold->isPositive() ? (string) $onHand->multipliedBy(100)->dividedBy($threshold, 1, RoundingMode::HALF_UP) : null,
            'state' => $state, 'needed_by_work_order' => (bool) $r->needed,
        ];
    }
}
