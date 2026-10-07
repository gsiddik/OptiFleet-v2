<?php

namespace App\Domain\Dashboard\Widgets\Finance;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\DashboardService;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * FN-04 Payables Aging — what is still owed now, by due-date bucket and source. Every source is
 * included only with its own view permission and module (it is not computed otherwise):
 *  VENDOR_INVOICE    vendor invoice references without a payment (paid at most once, in full);
 *                    due_date (nullable → "no due date"); scope = warehouse of the Purchase Order.
 *  SERVICE_INVOICE   Service Invoices (workshop_invoices) not CANCELLED and without a payment
 *                    (exactly one full payment per invoice); due_date; Work Order scope.
 *  EXTERNAL_WO       External WO invoices BILLED (not yet PAID): vendor amount − paid amount; the
 *                    payment term is free text, so there is no structured due date (owner decision 7).
 * Outstanding ≤ 0 is left out. Return-to-vendor refunds are not netted (not linked to invoices; FN-06).
 * Buckets (owner decision 5): not due, 1–30, 31–60, 61–90, > 90 days overdue, no due date.
 */
class PayablesAgingWidget extends Widget
{
    public const BUCKETS = ['not_due', 'd1_30', 'd31_60', 'd61_90', 'd90_plus', 'no_due'];

    public const SOURCES = [
        'VENDOR_INVOICE' => ['vendor_invoice.view', 'PROCUREMENT'],
        'SERVICE_INVOICE' => ['workshop_invoice.view', 'WORK_ORDER'],
        'EXTERNAL_WO' => ['external_work_order_invoice.view', 'WORK_ORDER'],
    ];

    public function id(): string
    {
        return 'FN-04';
    }

    public function unit(): string
    {
        return 'money';
    }

    public function modules(): array
    {
        return [];
    }

    public function filters(): array
    {
        return ['branch', 'workshop', 'warehouse'];
    }

    public function isAvailable(DashboardContext $context): bool
    {
        return $this->permittedSources($context) !== [];
    }

    /** @return list<string> */
    private function permittedSources(DashboardContext $context): array
    {
        return array_keys(array_filter(self::SOURCES, fn ($req) => $context->can($req[0]) && $context->hasModule($req[1])));
    }

    /**
     * Permitted sources that the active filters can narrow: a warehouse filter only applies to vendor
     * invoices (Purchase Order warehouse), a workshop filter only to Work Order invoices — a source the
     * filter cannot narrow is left out rather than shown unfiltered.
     *
     * @return list<string>
     */
    private function sources(DashboardContext $context): array
    {
        return array_values(array_filter($this->permittedSources($context), fn ($source) => match (true) {
            $context->filters->warehouseId !== null => $source === 'VENDOR_INVOICE',
            $context->filters->workshopId !== null => $source !== 'VENDOR_INVOICE',
            default => true,
        }));
    }

    public function compute(DashboardContext $context): array
    {
        $sources = $this->sources($context);
        if ($sources === []) {
            return ['data' => ['sources' => [], 'buckets' => [], 'total' => '0.00', 'overdue' => '0.00', 'invoices' => 0]];
        }
        $rows = DB::query()->fromSub($this->open($context, $sources), 'p')
            ->selectRaw('p.source, '.$this->bucketSql($context).' as bucket, count(*) as c, sum(p.outstanding) as amount')
            ->groupBy('p.source', 'bucket')->get();

        $buckets = [];
        foreach (self::BUCKETS as $bucket) {
            $buckets[$bucket] = ['bucket' => $bucket, 'count' => 0, 'total' => '0.00'];
            foreach ($sources as $source) {
                $buckets[$bucket][$source] = '0.00';
            }
        }
        foreach ($rows as $r) {
            $buckets[$r->bucket][$r->source] = self::money($r->amount);
            $buckets[$r->bucket]['count'] += (int) $r->c;
        }
        foreach ($buckets as &$b) {
            $b['total'] = self::moneySum(array_map(fn ($s) => $b[$s], $sources));
        }
        unset($b);

        $overdue = array_map(fn ($k) => $buckets[$k]['total'], ['d1_30', 'd31_60', 'd61_90', 'd90_plus']);

        return [
            'data' => [
                'sources' => $sources,
                'buckets' => array_values($buckets),
                'total' => self::moneySum(array_column($buckets, 'total')),
                'overdue' => self::moneySum($overdue),
                'invoices' => array_sum(array_column($buckets, 'count')),
            ],
            'limitations' => in_array('SERVICE_INVOICE', $sources, true) ? $this->foreignCurrency($context) : [],
        ];
    }

    public function detailRules(): ?array
    {
        return ['bucket' => ['nullable', 'in:'.implode(',', self::BUCKETS)], 'source' => ['nullable', 'in:'.implode(',', array_keys(self::SOURCES))]];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $sources = $this->sources($context);
        if (! empty($params['source'])) {
            $sources = array_values(array_intersect($sources, [$params['source']]));
        }
        if ($sources === []) {
            return ['items' => [], 'meta' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1]];
        }
        $query = DB::query()->fromSub($this->open($context, $sources), 'p')
            ->leftJoin('partners as pa', 'pa.id', '=', 'p.partner_id')
            ->select(['p.*', 'pa.name as vendor_name', DB::raw($this->bucketSql($context).' as bucket')])
            ->orderByRaw('p.due_date asc nulls last')->orderBy('p.document_number');
        if (! empty($params['bucket'])) {
            $query->whereRaw($this->bucketSql($context).' = ?', [$params['bucket']]);
        }

        return $this->paginate($query, $params, fn ($r) => [
            'id' => $r->source.':'.$r->document_id, 'source' => $r->source, 'document_id' => $r->document_id, 'document_number' => $r->document_number,
            'work_order_id' => $r->work_order_id, 'vendor_name' => $r->vendor_name, 'invoice_date' => $r->invoice_date, 'due_date' => $r->due_date,
            'payment_term' => $r->payment_term, 'days_overdue' => $r->due_date ? max(0, (int) self::daysSince($context, $r->due_date)) : null,
            'bucket' => $r->bucket, 'outstanding' => self::money($r->outstanding),
        ]);
    }

    private function bucketSql(DashboardContext $context): string
    {
        $today = DB::getPdo()->quote($context->todayDate());

        return "(case when p.due_date is null then 'no_due'
            when p.due_date >= {$today}::date then 'not_due'
            when {$today}::date - p.due_date <= 30 then 'd1_30'
            when {$today}::date - p.due_date <= 60 then 'd31_60'
            when {$today}::date - p.due_date <= 90 then 'd61_90'
            else 'd90_plus' end)";
    }

    /** Unpaid documents of the permitted sources: source, document, partner, dates, outstanding. */
    private function open(DashboardContext $context, array $sources): Builder
    {
        $parts = [];
        if (in_array('VENDOR_INVOICE', $sources, true)) {
            $q = DB::table('vendor_invoice_references as vir')
                ->leftJoin('purchase_orders as po', 'po.id', '=', 'vir.purchase_order_id')
                ->where('vir.tenant_id', $context->tenantId)
                ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('vendor_invoice_payments as vp')->whereColumn('vp.vendor_invoice_reference_id', 'vir.id'))
                ->where('vir.amount', '>', 0)
                ->selectRaw("'VENDOR_INVOICE' as source, vir.id as document_id, vir.vendor_invoice_number as document_number, vir.partner_id,
                    null::uuid as work_order_id, vir.vendor_invoice_date as invoice_date, vir.due_date, null::text as payment_term, vir.amount as outstanding");
            $parts[] = $context->scopeWarehouse($q, 'po.delivery_warehouse_id');
        }
        if (in_array('SERVICE_INVOICE', $sources, true)) {
            $q = DB::table('workshop_invoices as wi')->join('work_orders as wo', 'wo.id', '=', 'wi.work_order_id')
                ->where('wi.tenant_id', $context->tenantId)->whereNull('wi.deleted_at')->where('wi.status', '!=', 'CANCELLED')
                ->where('wi.currency', DashboardService::baseCurrency())
                ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('workshop_invoice_payments as wp')->whereColumn('wp.workshop_invoice_id', 'wi.id'))
                ->where('wi.total_amount', '>', 0)
                ->selectRaw("'SERVICE_INVOICE' as source, wi.id as document_id, wi.external_invoice_number as document_number, wi.partner_id,
                    wo.id as work_order_id, wi.invoice_date, wi.due_date, null::text as payment_term, wi.total_amount as outstanding");
            $parts[] = $context->scopeWorkOrder($q, 'wo.workshop_id', 'wo.branch_id');
        }
        if (in_array('EXTERNAL_WO', $sources, true)) {
            $q = DB::table('work_order_external_invoices as ei')->join('work_orders as wo', 'wo.id', '=', 'ei.work_order_id')
                ->where('ei.tenant_id', $context->tenantId)->where('ei.status', 'BILLED')->whereNotNull('ei.vendor_invoice_amount')
                ->whereRaw('ei.vendor_invoice_amount - coalesce(ei.paid_amount, 0) > 0')
                ->selectRaw("'EXTERNAL_WO' as source, ei.id as document_id, ei.wal_number as document_number, ei.wal_workshop_partner_id as partner_id,
                    wo.id as work_order_id, ei.vendor_invoice_date as invoice_date, null::date as due_date, ei.payment_term::text as payment_term,
                    ei.vendor_invoice_amount - coalesce(ei.paid_amount, 0) as outstanding");
            $parts[] = $context->scopeWorkOrder($q, 'wo.workshop_id', 'wo.branch_id');
        }

        $union = array_shift($parts);
        foreach ($parts as $part) {
            $union->unionAll($part);
        }

        return $union;
    }

    private function foreignCurrency(DashboardContext $context): array
    {
        $q = DB::table('workshop_invoices as wi')->join('work_orders as wo', 'wo.id', '=', 'wi.work_order_id')
            ->where('wi.tenant_id', $context->tenantId)->whereNull('wi.deleted_at')->where('wi.status', '!=', 'CANCELLED')
            ->where('wi.currency', '!=', DashboardService::baseCurrency())
            ->whereNotExists(fn ($s) => $s->select(DB::raw(1))->from('workshop_invoice_payments as wp')->whereColumn('wp.workshop_invoice_id', 'wi.id'));
        $context->scopeWorkOrder($q, 'wo.workshop_id', 'wo.branch_id');

        return $q->groupBy('wi.currency')->selectRaw('wi.currency, count(*) as c, sum(wi.total_amount) as amount')->get()
            ->map(fn ($r) => ['code' => 'dashboard.limitations.foreignCurrencyExcluded', 'params' => ['count' => (int) $r->c, 'currency' => $r->currency, 'amount' => self::money($r->amount)]])
            ->all();
    }
}
