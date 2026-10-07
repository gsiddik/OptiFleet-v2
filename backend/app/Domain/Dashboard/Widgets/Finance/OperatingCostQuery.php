<?php

namespace App\Domain\Dashboard\Widgets\Finance;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\DashboardService;
use App\Domain\Dashboard\WorkTime\WorkTimeQuery;
use App\Domain\WorkOrder\Models\WorkOrderPlannedPart;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Operating cost per vehicle — the single definition behind FN-07 (Most Costly Vehicle) and FN-08
 * (monthly cost mix). It is NOT the Service Cost of FN-01/02/03 (document basis): here cost is
 * recognized when it is actually incurred or paid (owner decisions 2–5):
 *
 *  PARTS          every CONSUME ledger entry of a Work Order part line, on its occurred_at:
 *                 consumed qty × issue-time unit cost (total_cost / issued qty, 4 dp), 2 dp per entry.
 *                 Consumption is never reversed (returns only cover unconsumed issued quantity); used
 *                 tires have no valuation and no ledger entry (cost 0 by the inventory rule).
 *  LABOR          mechanic cost of the work intervals ending in the period (an open interval counts
 *                 up to now, in the current month): overlap hours × assignment rate snapshot
 *                 (WorkTimeQuery). Rows without a rate snapshot have no amount and are reported.
 *  EXTERNAL_PAID  payments actually recorded: External WO settlements (payment_date, paid_amount —
 *                 always the full invoice amount) and Service Invoice payments (payment_date,
 *                 paid_amount) of invoices that are not cancelled, base currency only. An invoice is
 *                 never counted as cost in addition to its payment.
 * Never counted: estimates, planned/requested/issued quantities, tire or component purchase price.
 * Scope: Work Order access rule (workshop scope; branch filter = WO business branch).
 */
final class OperatingCostQuery
{
    public const COMPONENTS = ['PARTS', 'LABOR', 'EXTERNAL_PAID'];

    /**
     * Cost lines in [from, to) (tenant-local dates). Optional narrowing by vehicle / category / WO.
     *
     * @return Collection<int, array{component: string, vehicle_id: string, work_order_id: string, wo_number: string, month: string, on: string, amount: ?string, ref: array}>
     */
    public static function lines(DashboardContext $context, string $fromDate, string $toDateExclusive, array $narrow = []): Collection
    {
        [$fromUtc, $toUtc] = $context->utcBounds($fromDate, $toDateExclusive);

        $parts = self::workOrders($context, $narrow)
            ->join('work_order_planned_parts as pp', 'pp.work_order_id', '=', 'wo.id')
            ->join('stock_movements as sm', function ($j) {
                $j->on('sm.reference_id', '=', 'pp.id')->where('sm.reference_type', WorkOrderPlannedPart::class)->where('sm.movement_type', 'CONSUME');
            })
            ->leftJoin('products as p', 'p.id', '=', 'pp.product_id')
            ->where('sm.occurred_at', '>=', $fromUtc)->where('sm.occurred_at', '<', $toUtc)
            ->where('pp.issued_quantity', '>', 0)->whereNotNull('pp.total_cost')
            ->get(['wo.vehicle_id', 'wo.id as work_order_id', 'wo.wo_number', 'sm.id as ref_id', 'sm.occurred_at', 'sm.quantity',
                'p.name as product_name', 'p.sku', 'p.product_type',
                DB::raw('round(pp.total_cost / pp.issued_quantity, 4) as unit_cost'),
                DB::raw('round(sm.quantity * round(pp.total_cost / pp.issued_quantity, 4), 2) as amount'),
                DB::raw($context->localMonthSql('sm.occurred_at').' as month'), DB::raw($context->localDateSql('sm.occurred_at').' as on_date')])
            ->map(fn ($r) => [
                'component' => 'PARTS', 'vehicle_id' => $r->vehicle_id, 'work_order_id' => $r->work_order_id, 'wo_number' => $r->wo_number,
                'month' => $r->month, 'on' => (string) $r->on_date, 'amount' => self::money($r->amount),
                'ref' => ['id' => $r->ref_id, 'product_name' => $r->product_name, 'sku' => $r->sku, 'product_type' => $r->product_type,
                    'quantity' => self::decimal($r->quantity), 'unit_cost' => (string) BigDecimal::of((string) $r->unit_cost)->toScale(4)],
            ]);

        $intervals = WorkTimeQuery::intervals($context, self::workOrders($context, $narrow)->select('wo.id'), $fromUtc, $toUtc);
        $woInfo = DB::table('work_orders')->whereIn('id', $intervals->pluck('work_order_id')->unique()->values())
            ->get(['id', 'vehicle_id', 'wo_number'])->keyBy('id');
        $workers = DB::table('workers')->where('tenant_id', $context->tenantId)->pluck('name', 'id');
        $labor = collect(WorkTimeQuery::attribute($context, $intervals))->map(function ($row) use ($context, $woInfo, $workers, $intervals) {
            $end = CarbonImmutable::createFromTimestampUTC($row['ended_at'])->setTimezone($context->timezone);
            $wo = $woInfo[$row['work_order_id']];
            $interval = $intervals->firstWhere('id', $row['interval_id']);

            return [
                'component' => 'LABOR', 'vehicle_id' => $wo->vehicle_id, 'work_order_id' => $wo->id, 'wo_number' => $wo->wo_number,
                'month' => $end->format('Y-m'), 'on' => $end->toDateString(), 'amount' => $row['cost'],
                'ref' => ['id' => $row['interval_id'].':'.$row['worker_id'], 'worker_id' => $row['worker_id'], 'worker_name' => $workers[$row['worker_id']] ?? null,
                    'hours' => WorkTimeQuery::hours($row['seconds']), 'seconds' => $row['seconds'], 'cycle' => $interval->cycle, 'open' => $row['open']],
            ];
        });

        $base = DashboardService::baseCurrency();
        $settled = self::workOrders($context, $narrow)
            ->join('work_order_external_invoices as ei', 'ei.work_order_id', '=', 'wo.id')
            ->where('ei.status', 'PAID')->whereNotNull('ei.payment_date')->whereNotNull('ei.paid_amount')
            ->where('ei.payment_date', '>=', $fromDate)->where('ei.payment_date', '<', $toDateExclusive)
            ->get(['wo.vehicle_id', 'wo.id as work_order_id', 'wo.wo_number', 'ei.id as ref_id', 'ei.wal_number as document', 'ei.payment_date', 'ei.paid_amount',
                DB::raw("to_char(ei.payment_date, 'YYYY-MM') as month")])
            ->map(fn ($r) => self::externalLine($r, 'EXTERNAL_WO'));
        $servicePayments = self::workOrders($context, $narrow)
            ->join('workshop_invoices as wi', 'wi.work_order_id', '=', 'wo.id')
            ->join('workshop_invoice_payments as wp', 'wp.workshop_invoice_id', '=', 'wi.id')
            ->whereNull('wi.deleted_at')->where('wi.status', '!=', 'CANCELLED')->where('wi.currency', $base)
            ->where('wp.payment_date', '>=', $fromDate)->where('wp.payment_date', '<', $toDateExclusive)
            ->get(['wo.vehicle_id', 'wo.id as work_order_id', 'wo.wo_number', 'wp.id as ref_id', 'wi.external_invoice_number as document', 'wp.payment_date', 'wp.paid_amount',
                DB::raw("to_char(wp.payment_date, 'YYYY-MM') as month")])
            ->map(fn ($r) => self::externalLine($r, 'SERVICE_INVOICE'));

        return $parts->concat($labor)->concat($settled)->concat($servicePayments)->values();
    }

    /**
     * Totals per component (+ total) over lines; amounts of lines without a value are skipped.
     *
     * @return array<string, string>
     */
    public static function totals(Collection $lines): array
    {
        $out = [];
        foreach (self::COMPONENTS as $component) {
            $out[$component] = self::sum($lines->where('component', $component));
        }
        $out['total'] = self::sum($lines);

        return $out;
    }

    /** Lines grouped per vehicle: vehicle_id → totals. */
    public static function perVehicle(Collection $lines): Collection
    {
        return $lines->groupBy('vehicle_id')->map(fn (Collection $rows) => self::totals($rows));
    }

    /** Lines whose cost could not be valued (labor without a rate snapshot). */
    public static function limitations(DashboardContext $context, Collection $lines): array
    {
        $out = [];
        $missing = $lines->where('component', 'LABOR')->whereNull('amount');
        if ($missing->isNotEmpty()) {
            $out[] = ['code' => 'dashboard.limitations.laborRateMissing', 'params' => [
                'n' => $missing->count(), 'hours' => WorkTimeQuery::hours((int) $missing->sum(fn ($l) => $l['ref']['seconds'])),
            ]];
        }
        $open = $lines->where('component', 'LABOR')->filter(fn ($l) => $l['ref']['open'])->pluck('work_order_id')->unique();
        if ($open->isNotEmpty()) {
            $out[] = ['code' => 'dashboard.limitations.laborRunning', 'params' => ['n' => $open->count()]];
        }
        $started = $lines->pluck('work_order_id')->unique()->values()->all();
        $incomplete = collect(WorkTimeQuery::historyComplete($context, $started))
            ->filter(fn ($complete, $id) => ! $complete)->keys();
        $incomplete = DB::table('work_orders')->whereIn('id', $incomplete)->whereNotNull('started_at')->count();
        if ($incomplete > 0) {
            $out[] = ['code' => 'dashboard.limitations.laborHistoryIncomplete', 'params' => ['n' => $incomplete]];
        }

        return $out;
    }

    /** Accessible Work Orders (scope + filters + optional vehicle / category / WO narrowing). */
    public static function workOrders(DashboardContext $context, array $narrow = []): Builder
    {
        $query = DB::table('work_orders as wo')->where('wo.tenant_id', $context->tenantId)->whereNull('wo.deleted_at')
            ->when($narrow['vehicle_id'] ?? null, fn ($q, $id) => $q->where('wo.vehicle_id', $id))
            ->when($narrow['work_order_id'] ?? null, fn ($q, $id) => $q->where('wo.id', $id))
            ->when($narrow['vehicle_category_id'] ?? null, fn ($q, $id) => $q->whereIn('wo.vehicle_id',
                DB::table('vehicles')->where('tenant_id', $context->tenantId)->where('vehicle_category_id', $id)->select('id')));

        return $context->scopeWorkOrder($query, 'wo.workshop_id', 'wo.branch_id');
    }

    public static function sum(Collection $lines): string
    {
        return (string) $lines->reduce(fn (BigDecimal $carry, $l) => $l['amount'] === null ? $carry : $carry->plus($l['amount']), BigDecimal::zero())->toScale(2);
    }

    private static function externalLine(object $r, string $source): array
    {
        return [
            'component' => 'EXTERNAL_PAID', 'vehicle_id' => $r->vehicle_id, 'work_order_id' => $r->work_order_id, 'wo_number' => $r->wo_number,
            'month' => $r->month, 'on' => (string) $r->payment_date, 'amount' => self::money($r->paid_amount),
            'ref' => ['id' => $r->ref_id, 'source' => $source, 'document' => $r->document],
        ];
    }

    private static function money(mixed $value): ?string
    {
        return $value === null ? null : (string) BigDecimal::of((string) $value)->toScale(2, RoundingMode::HALF_UP);
    }

    private static function decimal(mixed $value): string
    {
        return (string) BigDecimal::of((string) $value)->stripTrailingZeros();
    }
}
