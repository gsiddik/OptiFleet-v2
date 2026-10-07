<?php

namespace App\Domain\Dashboard\Widgets\Procurement;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\DataBasis;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * PR-05 Procurement cycle — for Purchase Orders whose FIRST posted Goods Receipt falls in the period
 * (tenant-local date): days from the Purchase Request's creation to the PO order date (PRs have no
 * approval timestamp, so creation is the recorded start), from the PO order date to the first receipt,
 * and the total. Medians per month and per vendor (medians resist one very late order). POs without
 * a linked PR only have the PO → GR leg; POs without an order date are excluded (reported).
 */
class ProcurementCycleWidget extends Widget
{
    public function id(): string
    {
        return 'PR-05';
    }

    public function kind(): string
    {
        return self::KIND_PERIOD;
    }

    public function unit(): string
    {
        return 'days';
    }

    public function modules(): array
    {
        return ['PROCUREMENT'];
    }

    public function permissions(): array
    {
        return ['purchase_order.view'];
    }

    public function filters(): array
    {
        return ['branch', 'warehouse', 'period'];
    }

    public function version(): int
    {
        return 2; // basis: since PR creation, completeness
    }

    public function compute(DashboardContext $context): array
    {
        $median = fn (string $col) => "percentile_cont(0.5) within group (order by {$col})";
        $summary = fn (Builder $q) => $q->selectRaw("count(*) as orders, count(c.pr_to_po) as with_pr,
            {$median('c.pr_to_po')} as pr_to_po, {$median('c.po_to_gr')} as po_to_gr, {$median('c.pr_to_gr')} as pr_to_gr");
        $round = fn ($v) => $v === null ? null : round((float) $v, 1);
        $present = fn ($r) => ['orders' => (int) $r->orders, 'with_pr' => (int) $r->with_pr,
            'pr_to_po' => $round($r->pr_to_po), 'po_to_gr' => $round($r->po_to_gr), 'pr_to_gr' => $round($r->pr_to_gr)];

        $totals = $present($summary($this->cycles($context))->first());
        $byMonth = $summary($this->cycles($context))->addSelect('c.month')->groupBy('c.month')->get()->keyBy('month');
        $vendors = $summary($this->cycles($context))->addSelect(['c.partner_id', 'c.vendor_name'])->groupBy('c.partner_id', 'c.vendor_name')
            ->orderByDesc('orders')->limit(10)->get()
            ->map(fn ($r) => ['partner_id' => $r->partner_id, 'vendor_name' => $r->vendor_name] + $present($r))->all();
        $empty = ['orders' => 0, 'with_pr' => 0, 'pr_to_po' => null, 'po_to_gr' => null, 'pr_to_gr' => null];

        $limitations = [];
        $noDate = $this->firstReceipts($context)->whereNull('po.order_date')->count();
        if ($noDate > 0) {
            $limitations[] = ['code' => 'dashboard.limitations.poWithoutOrderDate', 'params' => ['count' => $noDate]];
        }

        // The start of the cycle is the PR CREATION date: the application records no approval timestamp, so nothing
        // here is "since approval". Orders without a PR (or an order date) have no full cycle and are counted as excluded.
        $completeness = DataBasis::completeness($totals['orders'] + $noDate, $totals['with_pr'], [
            'NO_PURCHASE_REQUEST' => $totals['orders'] - $totals['with_pr'], 'NO_ORDER_DATE' => $noDate,
        ]);

        return [
            'basis' => DataBasis::make(
                [['key' => 'PR_TO_PO', 'code' => 'PR_CREATED_TO_PO_ORDER_DATE'], ['key' => 'PO_TO_GR', 'code' => 'PO_ORDER_DATE_TO_FIRST_RECEIPT'], ['key' => 'PERIOD', 'code' => 'FIRST_POSTED_RECEIPT_DATE']],
                ['FIRST_POSTED_GOODS_RECEIPT_PER_PO'],
                ['PR_APPROVAL_DATE_NOT_RECORDED', 'PO_WITHOUT_PURCHASE_REQUEST_FOR_PR_STEP', 'UNPOSTED_RECEIPTS'],
                $completeness,
            ),
            'data' => [
                'totals' => $totals,
                'months' => array_map(fn ($m) => ['month' => $m, 'is_current' => $m === $context->currentMonth()]
                    + (isset($byMonth[$m]) ? $present($byMonth[$m]) : $empty), $context->months()),
                'vendors' => $vendors,
            ],
            'limitations' => $limitations,
        ];
    }

    public function detailRules(): ?array
    {
        return ['month' => ['nullable', 'date_format:Y-m'], 'partner_id' => ['nullable', 'uuid']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $query = $this->cycles($context)
            ->when($params['month'] ?? null, fn ($q, $m) => $q->where('c.month', $m))
            ->when($params['partner_id'] ?? null, fn ($q, $p) => $q->where('c.partner_id', $p))
            ->orderByDesc('c.po_to_gr')->select('c.*');

        return $this->paginate($query, $params, fn ($r) => [
            'id' => $r->po_id, 'po_number' => $r->po_number, 'pr_id' => $r->pr_id, 'pr_number' => $r->pr_number, 'vendor_name' => $r->vendor_name,
            'pr_created_on' => $r->pr_created_on, 'order_date' => $r->order_date, 'first_receipt_on' => $r->first_receipt_on,
            'pr_to_po' => $r->pr_to_po === null ? null : (int) $r->pr_to_po, 'po_to_gr' => (int) $r->po_to_gr, 'pr_to_gr' => $r->pr_to_gr === null ? null : (int) $r->pr_to_gr,
        ]);
    }

    /** One row per PO with its first posted receipt in the period (scope: delivery warehouse). */
    private function cycles(DashboardContext $context): Builder
    {
        $rows = $this->firstReceipts($context)->whereNotNull('po.order_date')
            ->leftJoin('purchase_requests as pr', 'pr.id', '=', 'po.purchase_request_id')
            ->leftJoin('partners as pa', 'pa.id', '=', 'po.partner_id')
            ->selectRaw('po.id as po_id, po.po_number, pr.id as pr_id, pr.pr_number, po.partner_id, pa.name as vendor_name,
                '.$context->localDateSql('pr.created_at').' as pr_created_on, po.order_date, fr.first_receipt_on,
                to_char(fr.first_receipt_on, \'YYYY-MM\') as month,
                (po.order_date - '.$context->localDateSql('pr.created_at').') as pr_to_po,
                (fr.first_receipt_on - po.order_date) as po_to_gr,
                (fr.first_receipt_on - '.$context->localDateSql('pr.created_at').') as pr_to_gr');

        return DB::query()->fromSub($rows, 'c');
    }

    private function firstReceipts(DashboardContext $context): Builder
    {
        $first = DB::table('goods_receipts')->where('tenant_id', $context->tenantId)->where('status', 'POSTED')->whereNotNull('received_at')
            ->groupBy('purchase_order_id')->selectRaw('purchase_order_id, min('.$context->localDateSql('received_at').') as first_receipt_on');
        $query = DB::table('purchase_orders as po')->joinSub($first, 'fr', 'fr.purchase_order_id', '=', 'po.id')
            ->where('po.tenant_id', $context->tenantId)
            ->where('fr.first_receipt_on', '>=', $context->periodStartDate())->where('fr.first_receipt_on', '<', $context->periodEndDateExclusive());

        return $context->scopeWarehouse($query, 'po.delivery_warehouse_id');
    }
}
