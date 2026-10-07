<?php

namespace App\Domain\Dashboard\Widgets\Warehouse;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\Widgets\Workshop\WorkOrderWidget;
use Brick\Math\BigDecimal;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * WH-02 Stockout & Below Reorder Point — stock rows in OUT or LOW state (WH-01 rule), flagged when an
 * open Work Order still needs the product from that warehouse (planned − issued > 0). Needed rows
 * come first, then the largest shortage (reorder point − available).
 */
class CriticalStockWidget extends StockHealthWidget
{
    public function id(): string
    {
        return 'WH-02';
    }

    public function compute(DashboardContext $context): array
    {
        $base = $this->critical($context);
        $count = (clone $base)->count();
        $needed = (clone $base)->whereRaw($this->neededSql($context))->count();
        $items = $this->rows($context)->limit(10)->get()->map(fn ($r) => $this->present($r))->all();

        return ['data' => ['count' => $count, 'needed_by_work_orders' => $needed, 'items' => $items]];
    }

    public function detailRules(): ?array
    {
        return ['state' => ['nullable', 'in:OUT,LOW'], 'needed' => ['nullable', 'boolean']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $query = $this->rows($context)
            ->when(($params['state'] ?? null) === 'OUT', fn ($q) => $q->whereRaw(self::OUT))
            ->when(($params['state'] ?? null) === 'LOW', fn ($q) => $q->whereRaw(self::LOW))
            ->when(! empty($params['needed']), fn ($q) => $q->whereRaw($this->neededSql($context)));

        return $this->paginate($query, $params, fn ($r) => $this->present($r));
    }

    private function critical(DashboardContext $context): Builder
    {
        return $this->stocks($context)->whereRaw('(('.self::OUT.') or ('.self::LOW.'))');
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

    private function rows(DashboardContext $context): Builder
    {
        return $this->critical($context)
            ->selectRaw('ws.id, ws.product_id, p.name as product_name, p.sku, ws.warehouse_id, w.name as warehouse_name,
                ws.quantity_on_hand, '.self::AVAILABLE.' as available, ws.reorder_point,
                ('.self::OUT.') as is_out, '.$this->neededSql($context).' as needed')
            ->orderByRaw('needed desc, available asc, (ws.reorder_point - '.self::AVAILABLE.') desc, p.name');
    }

    private function present(object $r): array
    {
        $available = self::decimal($r->available, 2);
        // An out-of-stock row may have no reorder point ("not set"): no shortage figure, never 0.
        $shortage = $r->reorder_point === null ? null : BigDecimal::of((string) $r->reorder_point)->minus($available);

        return [
            'id' => $r->id, 'product_id' => $r->product_id, 'product_name' => $r->product_name, 'sku' => $r->sku,
            'warehouse_id' => $r->warehouse_id, 'warehouse_name' => $r->warehouse_name,
            'on_hand' => self::decimal($r->quantity_on_hand), 'available' => $available,
            'reorder_point' => $r->reorder_point === null ? null : self::decimal($r->reorder_point),
            'shortage' => $shortage === null ? null : ($shortage->isPositive() ? self::decimal((string) $shortage) : '0.00'),
            'state' => $r->is_out ? 'OUT' : 'LOW', 'needed_by_work_order' => (bool) $r->needed,
        ];
    }
}
