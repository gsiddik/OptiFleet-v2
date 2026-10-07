<?php

namespace App\Domain\Dashboard\Widgets\Warehouse;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\DashboardPermissions;
use App\Domain\Dashboard\Widgets\Widget;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * WH-05 Slow-moving Stock — stock rows with quantity on hand whose last on-hand movement
 * (StockMovementWidget IN/OUT types or a stock opname) is older than 90 days. Rows with stock but no
 * movement record at all cannot be dated: they are listed separately as "no movement history", never
 * counted as slow. Value = on hand × moving-average cost (current).
 */
class SlowMovingStockWidget extends Widget
{
    public const IDLE_DAYS = 90;

    public function id(): string
    {
        return 'WH-05';
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
        return ['inventory.view', DashboardPermissions::FINANCE];
    }

    public function filters(): array
    {
        return ['branch', 'warehouse'];
    }

    public function compute(DashboardContext $context): array
    {
        $row = DB::query()->fromSub($this->stocks($context), 's')->selectRaw(
            "count(*) filter (where s.state = 'SLOW') as slow_count, coalesce(sum(s.value) filter (where s.state = 'SLOW'), 0) as slow_value,
             count(*) filter (where s.state = 'NO_HISTORY') as nohist_count, coalesce(sum(s.value) filter (where s.state = 'NO_HISTORY'), 0) as nohist_value"
        )->first();

        return ['data' => [
            'idle_days' => self::IDLE_DAYS,
            'slow' => ['count' => (int) $row->slow_count, 'value' => self::money($row->slow_value)],
            'no_history' => ['count' => (int) $row->nohist_count, 'value' => self::money($row->nohist_value)],
            'items' => $this->rows($context, 'SLOW')->limit(8)->get()->map(fn ($r) => $this->present($r, $context))->all(),
        ]];
    }

    public function detailRules(): ?array
    {
        return ['state' => ['nullable', 'in:SLOW,NO_HISTORY']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        return $this->paginate($this->rows($context, $params['state'] ?? 'SLOW'), $params, fn ($r) => $this->present($r, $context));
    }

    private function rows(DashboardContext $context, string $state): Builder
    {
        return DB::query()->fromSub($this->stocks($context), 's')->where('s.state', $state)->orderByDesc('s.value')->orderBy('s.product_name');
    }

    private function present(object $r, DashboardContext $context): array
    {
        return [
            'id' => $r->id, 'product_id' => $r->product_id, 'product_name' => $r->product_name, 'sku' => $r->sku, 'warehouse_name' => $r->warehouse_name,
            'quantity_on_hand' => self::decimal($r->quantity_on_hand), 'value' => self::money($r->value), 'state' => $r->state,
            'last_movement_at' => self::isoUtc($r->last_movement_at),
            'idle_days' => $r->last_movement_at ? (int) CarbonImmutable::parse($r->last_movement_at, 'UTC')->diffInDays($context->now, false) : null,
        ];
    }

    private function stocks(DashboardContext $context): Builder
    {
        $types = "'".implode("','", [...StockMovementWidget::IN, ...StockMovementWidget::OUT, 'STOCK_OPNAME'])."'";
        $threshold = DB::getPdo()->quote($context->now->utc()->subDays(self::IDLE_DAYS)->format('Y-m-d H:i:s'));
        $query = DB::table('warehouse_stocks as ws')
            ->join('products as p', 'p.id', '=', 'ws.product_id')
            ->join('warehouses as w', 'w.id', '=', 'ws.warehouse_id')
            ->where('ws.tenant_id', $context->tenantId)->whereNull('w.deleted_at')->where('ws.quantity_on_hand', '>', 0)
            ->selectRaw("ws.id, ws.product_id, p.name as product_name, p.sku, w.name as warehouse_name, ws.quantity_on_hand,
                ws.quantity_on_hand * ws.average_unit_cost as value, lm.last_movement_at,
                case when lm.last_movement_at is null then 'NO_HISTORY' when lm.last_movement_at < {$threshold}::timestamp then 'SLOW' else 'ACTIVE' end as state")
            ->leftJoinSub(DB::table('stock_movements')->where('tenant_id', $context->tenantId)->whereRaw("movement_type in ({$types})")
                ->groupBy('warehouse_id', 'product_id')->selectRaw('warehouse_id, product_id, max(occurred_at) as last_movement_at'),
                'lm', fn ($j) => $j->on('lm.warehouse_id', '=', 'ws.warehouse_id')->on('lm.product_id', '=', 'ws.product_id'));

        return $context->scopeWarehouse($query, 'ws.warehouse_id');
    }
}
