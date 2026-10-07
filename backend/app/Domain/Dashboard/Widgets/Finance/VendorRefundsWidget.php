<?php

namespace App\Domain\Dashboard\Widgets\Finance;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\DashboardPermissions;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * FN-06 Return-to-Vendor Refunds. Accepted refunds (refunded_amount, recorded when the vendor accepts)
 * per tenant-local month of vendor_decided_at, plus refund requests still waiting for the vendor. A
 * pending request has no amount yet (the refund value is entered on acceptance), so it is counted,
 * not valued. "Accepted" means agreed by the vendor — receipt of the money is not recorded.
 */
class VendorRefundsWidget extends Widget
{
    public function id(): string
    {
        return 'FN-06';
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
        return [DashboardPermissions::FINANCE];
    }

    public function filters(): array
    {
        return ['branch', 'warehouse', 'period'];
    }

    public function compute(DashboardContext $context): array
    {
        [$fromUtc, $toUtc] = $context->utcBounds($context->periodStartDate(), $context->periodEndDateExclusive());
        $accepted = $this->returns($context)->where('pr.status', 'REFUND_ACCEPTED')
            ->where('pr.vendor_decided_at', '>=', $fromUtc)->where('pr.vendor_decided_at', '<', $toUtc)
            ->groupBy('month')->selectRaw($context->localMonthSql('pr.vendor_decided_at').' as month, count(*) as c, sum(pr.refunded_amount) as amount')
            ->get()->keyBy('month');

        $months = [];
        foreach ($context->months() as $month) {
            $months[] = ['month' => $month, 'is_current' => $month === $context->currentMonth(),
                'count' => (int) ($accepted[$month]->c ?? 0), 'amount' => self::money($accepted[$month]->amount ?? 0)];
        }
        $pending = $this->returns($context)->where('pr.status', 'REFUND_REQUESTED')->count();

        return ['data' => [
            'months' => $months,
            'accepted_total' => self::moneySum(array_column($months, 'amount')),
            'accepted_count' => array_sum(array_column($months, 'count')),
            'pending_requests' => $pending,
        ]];
    }

    public function detailRules(): ?array
    {
        return ['view' => ['required', 'in:accepted,pending'], 'month' => ['nullable', 'date_format:Y-m']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $query = $this->returns($context)->leftJoin('partners as pa', 'pa.id', '=', 'pr.partner_id')
            ->leftJoin('purchase_orders as po', 'po.id', '=', 'pr.purchase_order_id');
        if ($params['view'] === 'pending') {
            $query->where('pr.status', 'REFUND_REQUESTED')->orderBy('pr.returned_at');
        } else {
            $month = $params['month'] ?? null;
            [$fromUtc, $toUtc] = $month && in_array($month, $context->months(), true)
                ? $context->monthUtcBounds($month)
                : $context->utcBounds($context->periodStartDate(), $context->periodEndDateExclusive());
            $query->where('pr.status', 'REFUND_ACCEPTED')->where('pr.vendor_decided_at', '>=', $fromUtc)->where('pr.vendor_decided_at', '<', $toUtc)
                ->orderByDesc('pr.vendor_decided_at');
        }
        $query->select(['pr.id', 'pr.return_number', 'pr.status', 'pr.returned_at', 'pr.vendor_decided_at', 'pr.refunded_amount',
            'pa.name as vendor_name', 'po.id as purchase_order_id', 'po.po_number']);

        return $this->paginate($query, $params, fn ($r) => [
            'id' => $r->id, 'return_number' => $r->return_number, 'status' => $r->status, 'vendor_name' => $r->vendor_name,
            'purchase_order_id' => $r->purchase_order_id, 'po_number' => $r->po_number, 'returned_at' => self::isoUtc($r->returned_at),
            'vendor_decided_at' => self::isoUtc($r->vendor_decided_at), 'refunded_amount' => $r->refunded_amount === null ? null : self::money($r->refunded_amount),
        ]);
    }

    private function returns(DashboardContext $context): Builder
    {
        $query = DB::table('purchase_returns as pr')->where('pr.tenant_id', $context->tenantId)->where('pr.return_option', 'REFUND');

        return $context->scopeWarehouse($query, 'pr.warehouse_id');
    }
}
