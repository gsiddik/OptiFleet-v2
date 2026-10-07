<?php

namespace App\Domain\Dashboard\Widgets\Procurement;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\DashboardPermissions;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * PR-03 Committed Purchase Order Value per month — PO total (document value: subtotal + tax + freight)
 * by order_date, for POs that were committed: APPROVED, ISSUED, PARTIALLY_RECEIVED, RECEIVED, CLOSED.
 * Drafts, submissions awaiting approval, rejected and cancelled POs are left out. POs without an order
 * date cannot be placed in a month and are reported.
 */
class PurchaseOrderValueWidget extends Widget
{
    public const COMMITTED = ['APPROVED', 'ISSUED', 'PARTIALLY_RECEIVED', 'RECEIVED', 'CLOSED'];

    public function id(): string
    {
        return 'PR-03';
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
        return ['PROCUREMENT'];
    }

    public function permissions(): array
    {
        return ['purchase_order.view', DashboardPermissions::FINANCE];
    }

    public function filters(): array
    {
        return ['branch', 'warehouse', 'period'];
    }

    public function compute(DashboardContext $context): array
    {
        $rows = $this->orders($context)
            ->where('po.order_date', '>=', $context->periodStartDate())->where('po.order_date', '<', $context->periodEndDateExclusive())
            ->groupBy('month')->selectRaw("to_char(po.order_date, 'YYYY-MM') as month, count(*) as c, sum(po.total) as amount")
            ->get()->keyBy('month');
        $months = [];
        foreach ($context->months() as $month) {
            $months[] = ['month' => $month, 'is_current' => $month === $context->currentMonth(),
                'count' => (int) ($rows[$month]->c ?? 0), 'amount' => self::money($rows[$month]->amount ?? 0)];
        }
        $undated = $this->orders($context)->whereNull('po.order_date')->count();

        return [
            'data' => ['months' => $months, 'total' => self::moneySum(array_column($months, 'amount')), 'orders' => array_sum(array_column($months, 'count'))],
            'limitations' => $undated > 0 ? [['code' => 'dashboard.limitations.poWithoutOrderDate', 'params' => ['count' => $undated]]] : [],
        ];
    }

    public function detailRules(): ?array
    {
        return ['month' => ['required', 'date_format:Y-m']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        [$from, $to] = $context->monthDateBounds($params['month']);
        $query = $this->orders($context)->where('po.order_date', '>=', $from)->where('po.order_date', '<', $to)
            ->leftJoin('partners as pa', 'pa.id', '=', 'po.partner_id')->leftJoin('warehouses as w', 'w.id', '=', 'po.delivery_warehouse_id')
            ->orderByDesc('po.total')
            ->select(['po.id', 'po.po_number', 'po.status', 'po.order_date', 'po.total', 'pa.name as vendor_name', 'w.name as warehouse_name']);

        return $this->paginate($query, $params, fn ($r) => [
            'id' => $r->id, 'po_number' => $r->po_number, 'status' => $r->status, 'order_date' => $r->order_date,
            'vendor_name' => $r->vendor_name, 'warehouse_name' => $r->warehouse_name, 'total' => self::money($r->total),
        ]);
    }

    private function orders(DashboardContext $context): Builder
    {
        $query = DB::table('purchase_orders as po')->where('po.tenant_id', $context->tenantId)->whereIn('po.status', self::COMMITTED);

        return $context->scopeWarehouse($query, 'po.delivery_warehouse_id');
    }
}
