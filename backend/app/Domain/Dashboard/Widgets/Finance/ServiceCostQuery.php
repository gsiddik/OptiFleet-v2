<?php

namespace App\Domain\Dashboard\Widgets\Finance;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\DashboardService;
use App\Domain\Dashboard\Widgets\Workshop\WorkOrderWidget;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Service Cost — the single definition behind FN-01 (per month), FN-02 (per vehicle) and FN-03 (per
 * branch), so their totals and drill-downs always reconcile. Owner decision 3:
 *
 *  PARTS             consumed part cost of Work Orders COMPLETED/CLOSED, recognized on the WO's
 *                    completed_at (tenant-local month). Per line: consumed qty × issue-time average unit
 *                    cost (total_cost / issued qty, 4 dp half-up), 2 dp half-up — the same rule as
 *                    WorkOrderPlannedPart::consumedTotalCost(). Returned quantity never counts.
 *  EXTERNAL_SERVICE  Service Invoices (workshop_invoices) of internal Work Orders, total_amount
 *                    (document value incl. tax), recognized on invoice_date; CANCELLED excluded,
 *                    correction/cancellation requests still count until decided. One row per
 *                    invoice, so an invoice is counted once whatever memo it settles.
 *  EXTERNAL_WO       External Work Order invoices BILLED/PAID, vendor_invoice_amount (document value,
 *                    no tax split recorded), recognized on vendor_invoice_date.
 *
 * Never counted: estimates (WO estimated_* costs, memo cost), payments (cash, not cost), tire /
 * component purchase cost (their use is already a consumed part), internal labor (no cost snapshot),
 * retread / repair cycles (TR-04). Invoices in a currency other than the base currency are left out
 * and reported. Scope: the Work Order access rule (workshop scope); the branch filter narrows by the
 * Work Order's business branch.
 */
final class ServiceCostQuery
{
    public const SOURCES = ['PARTS', 'EXTERNAL_SERVICE', 'EXTERNAL_WO'];

    public const RECOGNIZED_WO_STATUSES = ['COMPLETED', 'CLOSED'];

    /**
     * One row per cost transaction in the period: source, month (YYYY-MM, tenant-local), recognized_on,
     * vehicle_id, branch_id, work_order_id, wo_number, document_id, document_number, partner_id, amount.
     */
    public static function transactions(DashboardContext $context, ?string $fromDate = null, ?string $toDateExclusive = null): Builder
    {
        $from = $fromDate ?? $context->periodStartDate();
        $to = $toDateExclusive ?? $context->periodEndDateExclusive();
        [$fromUtc, $toUtc] = $context->utcBounds($from, $to);
        $base = DashboardService::baseCurrency();

        $parts = DB::table('work_order_planned_parts as pp')
            ->join('work_orders as wo', 'wo.id', '=', 'pp.work_order_id')
            ->where('wo.tenant_id', $context->tenantId)->whereNull('wo.deleted_at')
            ->whereIn('wo.status', self::RECOGNIZED_WO_STATUSES)
            ->where('wo.completed_at', '>=', $fromUtc)->where('wo.completed_at', '<', $toUtc)
            ->where('pp.issued_quantity', '>', 0)->whereNotNull('pp.total_cost')->where('pp.consumed_quantity', '>', 0)
            ->groupBy('wo.id', 'wo.wo_number', 'wo.vehicle_id', 'wo.branch_id', 'wo.completed_at')
            ->selectRaw("'PARTS' as source, ".$context->localMonthSql('wo.completed_at').' as month, '.$context->localDateSql('wo.completed_at').' as recognized_on,
                wo.vehicle_id, wo.branch_id, wo.id as work_order_id, wo.wo_number, wo.id as document_id, wo.wo_number as document_number,
                null::uuid as partner_id, sum(round(pp.consumed_quantity * round(pp.total_cost / pp.issued_quantity, 4), 2)) as amount');
        $context->scopeWorkOrder($parts, 'wo.workshop_id', 'wo.branch_id');

        $services = DB::table('workshop_invoices as wi')
            ->join('work_orders as wo', 'wo.id', '=', 'wi.work_order_id')
            ->where('wi.tenant_id', $context->tenantId)->whereNull('wi.deleted_at')->where('wi.status', '!=', 'CANCELLED')
            ->where('wi.currency', $base)
            ->where('wi.invoice_date', '>=', $from)->where('wi.invoice_date', '<', $to)
            ->selectRaw("'EXTERNAL_SERVICE' as source, to_char(wi.invoice_date, 'YYYY-MM') as month, wi.invoice_date as recognized_on,
                wo.vehicle_id, wo.branch_id, wo.id as work_order_id, wo.wo_number, wi.id as document_id, wi.external_invoice_number as document_number,
                wi.partner_id, wi.total_amount as amount");
        $context->scopeWorkOrder($services, 'wo.workshop_id', 'wo.branch_id');

        $external = DB::table('work_order_external_invoices as ei')
            ->join('work_orders as wo', 'wo.id', '=', 'ei.work_order_id')
            ->where('ei.tenant_id', $context->tenantId)->whereIn('ei.status', ['BILLED', 'PAID'])->whereNotNull('ei.vendor_invoice_amount')
            ->where('ei.vendor_invoice_date', '>=', $from)->where('ei.vendor_invoice_date', '<', $to)
            ->selectRaw("'EXTERNAL_WO' as source, to_char(ei.vendor_invoice_date, 'YYYY-MM') as month, ei.vendor_invoice_date as recognized_on,
                wo.vehicle_id, wo.branch_id, wo.id as work_order_id, wo.wo_number, ei.id as document_id, ei.wal_number as document_number,
                ei.wal_workshop_partner_id as partner_id, ei.vendor_invoice_amount as amount");
        $context->scopeWorkOrder($external, 'wo.workshop_id', 'wo.branch_id');

        return DB::query()->fromSub($parts->unionAll($services)->unionAll($external), 'tx');
    }

    /** Service Invoices in another currency inside the period: left out of every total, reported per currency. */
    public static function foreignCurrencyLimitations(DashboardContext $context): array
    {
        $query = DB::table('workshop_invoices as wi')->join('work_orders as wo', 'wo.id', '=', 'wi.work_order_id')
            ->where('wi.tenant_id', $context->tenantId)->whereNull('wi.deleted_at')->where('wi.status', '!=', 'CANCELLED')
            ->where('wi.currency', '!=', DashboardService::baseCurrency())
            ->where('wi.invoice_date', '>=', $context->periodStartDate())->where('wi.invoice_date', '<', $context->periodEndDateExclusive());
        $context->scopeWorkOrder($query, 'wo.workshop_id', 'wo.branch_id');

        return $query->groupBy('wi.currency')->selectRaw('wi.currency, count(*) as c, sum(wi.total_amount) as amount')->get()
            ->map(fn ($r) => ['code' => 'dashboard.limitations.foreignCurrencyExcluded', 'params' => [
                'count' => (int) $r->c, 'currency' => $r->currency, 'amount' => self::money($r->amount),
            ]])->all();
    }

    /** Not yet recognized: consumed parts on open Work Orders, and completed memos without an invoice. */
    public static function pending(DashboardContext $context): array
    {
        $parts = DB::table('work_order_planned_parts as pp')->join('work_orders as wo', 'wo.id', '=', 'pp.work_order_id')
            ->where('wo.tenant_id', $context->tenantId)->whereNull('wo.deleted_at')->whereIn('wo.status', WorkOrderWidget::OPEN_STATUSES)
            ->where('pp.issued_quantity', '>', 0)->whereNotNull('pp.total_cost')->where('pp.consumed_quantity', '>', 0);
        $context->scopeWorkOrder($parts, 'wo.workshop_id', 'wo.branch_id');
        $partsRow = $parts->selectRaw('count(distinct wo.id) as c, coalesce(sum(round(pp.consumed_quantity * round(pp.total_cost / pp.issued_quantity, 4), 2)), 0) as amount')->first();

        $memos = DB::table('work_order_external_services as es')->join('work_orders as wo', 'wo.id', '=', 'es.work_order_id')
            ->where('es.tenant_id', $context->tenantId)->where('es.status', 'COMPLETED')->whereNull('es.workshop_invoice_id');
        $context->scopeWorkOrder($memos, 'wo.workshop_id', 'wo.branch_id');
        $memoRow = $memos->selectRaw('count(*) as c, coalesce(sum(es.cost), 0) as amount')->first();

        return [
            'parts_on_open_work_orders' => ['work_orders' => (int) $partsRow->c, 'amount' => self::money($partsRow->amount)],
            'uninvoiced_memos' => ['count' => (int) $memoRow->c, 'estimated_amount' => self::money($memoRow->amount)],
        ];
    }

    private static function money(mixed $value): string
    {
        return (string) BigDecimal::of((string) ($value ?? '0'))->toScale(2, RoundingMode::HALF_UP);
    }
}
