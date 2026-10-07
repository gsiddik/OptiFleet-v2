<?php

namespace App\Domain\Dashboard\Widgets\Finance;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\DashboardPermissions;
use App\Domain\Dashboard\Widgets\Widget;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Shared base of FN-01/02/03: same transactions (ServiceCostQuery), different grouping. */
abstract class ServiceCostWidget extends Widget
{
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
        return ['WORK_ORDER'];
    }

    public function permissions(): array
    {
        return [DashboardPermissions::FINANCE];
    }

    public function filters(): array
    {
        return ['branch', 'workshop', 'period'];
    }

    /** Sums per source as money strings + total. */
    protected static function sourceSums(object|array $row): array
    {
        $row = (array) $row;
        $out = [];
        foreach (ServiceCostQuery::SOURCES as $source) {
            $out[$source] = self::money($row[$source] ?? 0);
        }
        $out['total'] = self::moneySum(array_values($out));

        return $out;
    }

    protected static function pivotSelect(): string
    {
        return collect(ServiceCostQuery::SOURCES)
            ->map(fn ($s) => "coalesce(sum(tx.amount) filter (where tx.source = '{$s}'), 0) as \"{$s}\"")
            ->implode(', ');
    }

    /** Per-vehicle totals of a transactions query (largest first). */
    protected function perVehicle(Builder $transactions): Builder
    {
        return $transactions->leftJoin('vehicles as v', 'v.id', '=', 'tx.vehicle_id')
            ->leftJoin('branches as b', 'b.id', '=', 'v.branch_id')
            ->groupBy('tx.vehicle_id', 'v.registration_number', 'b.name')
            ->selectRaw('tx.vehicle_id, v.registration_number, b.name as branch_name, count(distinct tx.work_order_id) as work_orders, '
                .self::pivotSelect().', sum(tx.amount) as total')
            ->orderByRaw('sum(tx.amount) desc')->orderBy('v.registration_number');
    }

    protected function presentVehicle(object $r): array
    {
        return ['vehicle_id' => $r->vehicle_id, 'registration_number' => $r->registration_number, 'branch_name' => $r->branch_name,
            'work_orders' => (int) $r->work_orders] + self::sourceSums($r);
    }

    /** Source transactions with display columns, newest first. */
    protected function transactionRows(Builder $transactions): Builder
    {
        return $transactions->leftJoin('vehicles as v', 'v.id', '=', 'tx.vehicle_id')
            ->leftJoin('partners as pa', 'pa.id', '=', 'tx.partner_id')
            ->select(['tx.source', 'tx.recognized_on', 'tx.month', 'tx.vehicle_id', 'v.registration_number', 'tx.work_order_id', 'tx.wo_number',
                'tx.document_id', 'tx.document_number', 'pa.name as vendor_name', 'tx.amount'])
            ->orderByDesc('tx.recognized_on')->orderBy('tx.source')->orderBy('tx.document_number');
    }

    protected function presentTransaction(object $r): array
    {
        return [
            'id' => $r->source.':'.$r->document_id, 'source' => $r->source, 'recognized_on' => substr((string) $r->recognized_on, 0, 10), 'month' => $r->month,
            'vehicle_id' => $r->vehicle_id, 'registration_number' => $r->registration_number, 'work_order_id' => $r->work_order_id,
            'wo_number' => $r->wo_number, 'document_id' => $r->document_id, 'document_number' => $r->document_number,
            'vendor_name' => $r->vendor_name, 'amount' => self::money($r->amount),
        ];
    }

    /** Totals for a whole transactions query, for reconciliation lines. */
    protected static function totals(Builder $transactions): array
    {
        return self::sourceSums(DB::query()->fromSub($transactions, 'tx')->selectRaw(self::pivotSelect())->first());
    }

    protected static function vehicleCount(DashboardContext $context): int
    {
        return (int) DB::query()->fromSub(ServiceCostQuery::transactions($context), 'tx')->distinct()->count('tx.vehicle_id');
    }
}
