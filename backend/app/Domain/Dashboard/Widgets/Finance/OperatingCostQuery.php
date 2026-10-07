<?php

namespace App\Domain\Dashboard\Widgets\Finance;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\DataBasis;
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

    /**
     * What a reader must know about the figure: labor rows without a rate snapshot, work still running,
     * Work Orders whose work-time history is missing / starts late, and payment records that break the
     * payment contract. Each is a count, never folded into a value.
     */
    public static function limitations(DashboardContext $context, Collection $lines, ?array $coverage = null, ?Collection $anomalies = null): array
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
        foreach ($coverage['completeness']['reasons'] ?? [] as $reason) {
            $code = match ($reason['code']) {
                'WORK_TIME_UNAVAILABLE' => 'dashboard.limitations.workTimeUnavailable',
                'WORK_TIME_PARTIAL' => 'dashboard.limitations.workTimePartial',
                default => null,
            };
            if ($code) {
                $out[] = ['code' => $code, 'params' => ['n' => $reason['n']]];
            }
        }
        if ($anomalies && $anomalies->isNotEmpty()) {
            $out[] = ['code' => 'dashboard.limitations.paymentAnomalies', 'params' => ['n' => $anomalies->count()]];
        }

        return $out;
    }

    /**
     * Work-time coverage of the Work Orders behind a cost total. Population = internal Work Orders in
     * scope that were started and either carry a cost line in the period or were started / completed in it.
     * Per Work Order (WorkTimeQuery::historyState): COMPLETE counts as valid; PARTIAL (history starts late)
     * and UNAVAILABLE (started before intervals existed) are excluded from "complete" — their recorded
     * cost, if any, is still shown but flagged; nothing is estimated. Rows without a rate snapshot are
     * counted too. Returns per-Work-Order states and per-vehicle labor status:
     *  NONE         no started Work Order — a valid zero;
     *  AVAILABLE    every started Work Order has complete history and every row is valued;
     *  PARTIAL      some value recorded, some Work Order / row not valued or incomplete;
     *  UNAVAILABLE  started Work Orders exist but no mechanic cost can be valued — never shown as 0.
     *
     * @return array{population: list<array{id: string, vehicle_id: string, state: string, open: bool}>, completeness: array<string, mixed>, vehicles: array<string, string>}
     */
    public static function laborCoverage(DashboardContext $context, Collection $lines, string $fromDate, string $toDateExclusive, array $narrow = []): array
    {
        [$fromUtc, $toUtc] = $context->utcBounds($fromDate, $toDateExclusive);
        $withLines = $lines->pluck('work_order_id')->unique()->values()->all();
        $ids = self::workOrders($context, $narrow)->where('wo.execution_mode', 'INTERNAL')->whereNotNull('wo.started_at')
            ->where(fn ($q) => $q->whereIn('wo.id', $withLines)
                ->orWhere(fn ($r) => $r->where('wo.started_at', '>=', $fromUtc)->where('wo.started_at', '<', $toUtc))
                ->orWhere(fn ($r) => $r->where('wo.completed_at', '>=', $fromUtc)->where('wo.completed_at', '<', $toUtc)))
            ->get(['wo.id', 'wo.vehicle_id']);
        $state = WorkTimeQuery::historyState($context, $ids->pluck('id')->all());
        $open = array_flip(WorkTimeQuery::openWorkOrders($context, $ids->pluck('id')->all()));
        $population = $ids->map(fn ($r) => ['id' => $r->id, 'vehicle_id' => $r->vehicle_id, 'state' => $state[$r->id] ?? WorkTimeQuery::UNAVAILABLE, 'open' => isset($open[$r->id])])->all();

        $labor = $lines->where('component', 'LABOR');
        $unvalued = $labor->whereNull('amount');
        $valued = $labor->whereNotNull('amount');
        $count = fn (string $s) => count(array_filter($population, fn ($w) => $w['state'] === $s));
        $complete = $count(WorkTimeQuery::COMPLETE);
        $total = count($population);
        $rateMissing = $unvalued->pluck('work_order_id')->unique()->count();

        $vehicles = [];
        foreach (collect($population)->groupBy('vehicle_id') as $vehicleId => $set) {
            $bad = $set->filter(fn ($w) => $w['state'] !== WorkTimeQuery::COMPLETE)->count()
                + $set->filter(fn ($w) => $w['state'] === WorkTimeQuery::COMPLETE && $unvalued->contains('work_order_id', $w['id']))->count();
            $hasValue = $valued->where('vehicle_id', $vehicleId)->isNotEmpty();
            $vehicles[$vehicleId] = $bad === 0 ? 'AVAILABLE' : ($hasValue ? 'PARTIAL' : 'UNAVAILABLE');
        }

        $completeness = DataBasis::completeness($total, max(0, $complete - $rateMissing), [
            'WORK_TIME_PARTIAL' => $count(WorkTimeQuery::PARTIAL),
            'WORK_TIME_UNAVAILABLE' => $count(WorkTimeQuery::UNAVAILABLE),
            'RATE_MISSING' => $rateMissing,
        ], count($open));

        return ['population' => $population, 'completeness' => $completeness, 'vehicles' => $vehicles];
    }

    /** Standard basis block of the operating-cost widgets (FN-07 / FN-08). */
    public static function basis(DashboardContext $context, array $coverage): array
    {
        return DataBasis::make(
            [['key' => 'PARTS', 'code' => 'CONSUMPTION_DATE'], ['key' => 'LABOR', 'code' => 'WORK_INTERVAL_END'], ['key' => 'EXTERNAL_PAID', 'code' => 'PAYMENT_DATE']],
            ['PARTS_CONSUMED', 'MECHANIC_TIME', 'EXTERNAL_PAYMENTS'],
            ['ESTIMATES', 'UNPAID_INVOICES', 'PURCHASE_PRICE', 'NON_BASE_CURRENCY', 'WORK_TIME_BEFORE_HISTORY'],
            $coverage['completeness'],
            WorkTimeQuery::historyAvailableFrom($context),
        );
    }

    /**
     * Payment records that do not follow the application's payment contract (one full payment per
     * invoice; no partial payment, no reversal). They are REPORTED, never altered or dropped silently:
     *  EXTERNAL_PARTIAL      a PAID external invoice whose paid amount differs from the vendor invoice amount;
     *  EXTERNAL_INCOMPLETE   a PAID external invoice without payment date or paid amount (not counted);
     *  SERVICE_PARTIAL       a Service Invoice payment whose amount differs from the invoice total;
     *  SERVICE_CANCELLED_PAID a cancelled Service Invoice that still has a payment row (money recorded as paid, not counted);
     *  NON_BASE_CURRENCY     a Service Invoice payment in another currency (not added to base-currency cost).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public static function paymentAnomalies(DashboardContext $context, string $fromDate, string $toDateExclusive, array $narrow = []): Collection
    {
        $base = DashboardService::baseCurrency();
        $external = self::workOrders($context, $narrow)->join('work_order_external_invoices as ei', 'ei.work_order_id', '=', 'wo.id')->where('ei.status', 'PAID')
            ->where(fn ($q) => $q->where(fn ($r) => $r->whereBetween('ei.payment_date', [$fromDate, date('Y-m-d', strtotime($toDateExclusive.' -1 day'))]))->orWhereNull('ei.payment_date'))
            ->get(['wo.id as work_order_id', 'wo.wo_number', 'wo.vehicle_id', 'ei.id as ref_id', 'ei.wal_number as document', 'ei.payment_date', 'ei.vendor_invoice_amount as expected', 'ei.paid_amount as paid'])
            ->map(function ($r) {
                $kind = match (true) {
                    $r->payment_date === null || $r->paid === null => 'EXTERNAL_INCOMPLETE',
                    $r->expected !== null && BigDecimal::of((string) $r->expected)->compareTo((string) $r->paid) !== 0 => 'EXTERNAL_PARTIAL',
                    default => null,
                };

                return $kind ? self::anomaly($kind, $r) : null;
            })->filter();

        $service = self::workOrders($context, $narrow)->join('workshop_invoices as wi', 'wi.work_order_id', '=', 'wo.id')->join('workshop_invoice_payments as wp', 'wp.workshop_invoice_id', '=', 'wi.id')
            ->whereNull('wi.deleted_at')->where('wp.payment_date', '>=', $fromDate)->where('wp.payment_date', '<', $toDateExclusive)
            ->get(['wo.id as work_order_id', 'wo.wo_number', 'wo.vehicle_id', 'wp.id as ref_id', 'wi.external_invoice_number as document', 'wp.payment_date', 'wi.total_amount as expected', 'wp.paid_amount as paid', 'wi.status', 'wi.currency'])
            ->map(function ($r) use ($base) {
                $kind = match (true) {
                    $r->status === 'CANCELLED' => 'SERVICE_CANCELLED_PAID',
                    $r->currency !== $base => 'NON_BASE_CURRENCY',
                    BigDecimal::of((string) $r->expected)->compareTo((string) $r->paid) !== 0 => 'SERVICE_PARTIAL',
                    default => null,
                };

                return $kind ? self::anomaly($kind, $r) : null;
            })->filter();

        return $external->concat($service)->values();
    }

    private static function anomaly(string $kind, object $r): array
    {
        return ['kind' => $kind, 'work_order_id' => $r->work_order_id, 'wo_number' => $r->wo_number, 'vehicle_id' => $r->vehicle_id, 'ref_id' => $r->ref_id, 'document' => $r->document,
            'payment_date' => $r->payment_date === null ? null : (string) $r->payment_date,
            'expected' => $r->expected === null ? null : self::money($r->expected), 'paid' => $r->paid === null ? null : self::money($r->paid)];
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
