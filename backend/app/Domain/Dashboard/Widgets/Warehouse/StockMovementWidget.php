<?php

namespace App\Domain\Dashboard\Widgets\Warehouse;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\DashboardPermissions;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * WH-04 Stock Movement Value per month — value IN and value OUT shown separately (never netted), from
 * stock_movements (quantity × unit cost recorded on the movement, tenant-local month of occurred_at).
 *  IN:  OPENING, RECEIPT, TRANSFER_IN, RETURN, ADJUSTMENT_PLUS
 *  OUT: ISSUE, TRANSFER_OUT, ADJUSTMENT_MINUS, SCRAP, RETURN_TO_VENDOR
 * Not movements of on-hand stock (left out): CONSUME, SALE, REMOVED_COMPONENT_RETURN, reservations.
 * Movements recorded without a unit cost (adjustments, scrap) are counted but not valued, and stock
 * opname variances are excluded (their direction is not recorded) — both reported as limitations.
 */
class StockMovementWidget extends Widget
{
    public const IN = ['OPENING', 'RECEIPT', 'TRANSFER_IN', 'RETURN', 'ADJUSTMENT_PLUS'];

    public const OUT = ['ISSUE', 'TRANSFER_OUT', 'ADJUSTMENT_MINUS', 'SCRAP', 'RETURN_TO_VENDOR'];

    public function id(): string
    {
        return 'WH-04';
    }

    public function kind(): string
    {
        return self::KIND_PERIOD;
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
        return ['branch', 'warehouse', 'period'];
    }

    public function compute(DashboardContext $context): array
    {
        $in = "'".implode("','", self::IN)."'";
        $out = "'".implode("','", self::OUT)."'";
        $rows = $this->movements($context)->whereIn('sm.movement_type', [...self::IN, ...self::OUT])
            ->groupBy('month')
            ->selectRaw($context->localMonthSql('sm.occurred_at')." as month,
                coalesce(sum(sm.quantity * sm.unit_cost) filter (where sm.movement_type in ({$in}) and sm.unit_cost is not null), 0) as value_in,
                coalesce(sum(sm.quantity * sm.unit_cost) filter (where sm.movement_type in ({$out}) and sm.unit_cost is not null), 0) as value_out,
                count(*) filter (where sm.unit_cost is null) as unvalued")
            ->get()->keyBy('month');

        $months = [];
        $unvalued = 0;
        foreach ($context->months() as $month) {
            $r = $rows[$month] ?? null;
            $unvalued += (int) ($r->unvalued ?? 0);
            $months[] = ['month' => $month, 'is_current' => $month === $context->currentMonth(),
                'value_in' => self::money($r->value_in ?? 0), 'value_out' => self::money($r->value_out ?? 0)];
        }
        $opname = $this->movements($context)->where('sm.movement_type', 'STOCK_OPNAME')->count();

        $limitations = [];
        if ($unvalued > 0) {
            $limitations[] = ['code' => 'dashboard.limitations.unvaluedMovements', 'params' => ['count' => $unvalued]];
        }
        if ($opname > 0) {
            $limitations[] = ['code' => 'dashboard.limitations.opnameExcluded', 'params' => ['count' => $opname]];
        }

        return [
            'data' => ['months' => $months, 'total_in' => self::moneySum(array_column($months, 'value_in')), 'total_out' => self::moneySum(array_column($months, 'value_out'))],
            'limitations' => $limitations,
        ];
    }

    public function detailRules(): ?array
    {
        return ['month' => ['required', 'date_format:Y-m']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        if (! in_array($params['month'], $context->months(), true)) {
            return ['items' => [], 'meta' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1]];
        }
        [$from, $to] = $context->monthUtcBounds($params['month']);
        $query = $this->movements($context, $from, $to)->whereIn('sm.movement_type', [...self::IN, ...self::OUT, 'STOCK_OPNAME'])
            ->groupBy('sm.movement_type')
            ->selectRaw('sm.movement_type, count(*) as movements, sum(sm.quantity) as quantity,
                coalesce(sum(sm.quantity * sm.unit_cost) filter (where sm.unit_cost is not null), 0) as value, count(*) filter (where sm.unit_cost is null) as unvalued')
            ->orderBy('sm.movement_type');

        return $this->paginate($query, $params, fn ($r) => [
            'id' => $r->movement_type, 'movement_type' => $r->movement_type,
            'direction' => in_array($r->movement_type, self::IN, true) ? 'IN' : (in_array($r->movement_type, self::OUT, true) ? 'OUT' : 'UNKNOWN'),
            'movements' => (int) $r->movements, 'quantity' => self::decimal($r->quantity), 'value' => self::money($r->value), 'unvalued' => (int) $r->unvalued,
        ]);
    }

    private function movements(DashboardContext $context, ?string $fromUtc = null, ?string $toUtc = null): Builder
    {
        if ($fromUtc === null) {
            [$fromUtc, $toUtc] = $context->utcBounds($context->periodStartDate(), $context->periodEndDateExclusive());
        }
        $query = DB::table('stock_movements as sm')->where('sm.tenant_id', $context->tenantId)
            ->where('sm.occurred_at', '>=', $fromUtc)->where('sm.occurred_at', '<', $toUtc);

        return $context->scopeWarehouse($query, 'sm.warehouse_id');
    }
}
