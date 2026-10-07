<?php

namespace App\Domain\Dashboard\Widgets\Tire;

use App\Domain\Dashboard\DashboardContext;
use App\Domain\Dashboard\DashboardPermissions;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * TR-04 Tire Service Cost — retread and repair cycle cost by the month the tire came back from the
 * vendor (received_at, a date), whatever the final inspection result (the cost was incurred). Kept
 * out of Service Cost (owner decision 3). Cycles without a recorded cost are counted, not valued.
 */
class TireServiceCostWidget extends TireWidget
{
    public function id(): string
    {
        return 'TR-04';
    }

    public function kind(): string
    {
        return self::KIND_PERIOD;
    }

    public function unit(): string
    {
        return 'money';
    }

    public function permissions(): array
    {
        return ['tire.view', DashboardPermissions::FINANCE];
    }

    public function filters(): array
    {
        return ['branch', 'warehouse', 'period'];
    }

    public function compute(DashboardContext $context): array
    {
        $rows = DB::query()->fromSub($this->cycles($context), 'c')->groupBy('c.month', 'c.type')
            ->selectRaw('c.month, c.type, count(*) as n, coalesce(sum(c.cost), 0) as amount, count(*) filter (where c.cost is null) as uncosted')->get();
        $months = [];
        foreach ($context->months() as $month) {
            $months[$month] = ['month' => $month, 'is_current' => $month === $context->currentMonth(), 'RETREAD' => '0.00', 'REPAIR' => '0.00', 'cycles' => 0];
        }
        $uncosted = 0;
        foreach ($rows as $r) {
            $months[$r->month][$r->type] = self::money($r->amount);
            $months[$r->month]['cycles'] += (int) $r->n;
            $uncosted += (int) $r->uncosted;
        }
        $months = array_values($months);

        return [
            'data' => ['months' => $months, 'retread_total' => self::moneySum(array_column($months, 'RETREAD')),
                'repair_total' => self::moneySum(array_column($months, 'REPAIR')), 'cycles' => array_sum(array_column($months, 'cycles'))],
            'limitations' => $uncosted > 0 ? [['code' => 'dashboard.limitations.cyclesWithoutCost', 'params' => ['count' => $uncosted]]] : [],
        ];
    }

    public function detailRules(): ?array
    {
        return ['month' => ['required', 'date_format:Y-m']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $query = DB::query()->fromSub($this->cycles($context), 'c')->where('c.month', $params['month'])
            ->leftJoin('partners as pa', 'pa.id', '=', 'c.partner_id')
            ->orderByDesc('c.received_at')->select(['c.*', 'pa.name as vendor_name']);

        return $this->paginate($query, $params, fn ($r) => [
            'id' => $r->id, 'type' => $r->type, 'tire_id' => $r->tire_id, 'serial_number' => $r->serial_number, 'cycle_number' => (int) $r->cycle_number,
            'vendor_name' => $r->vendor_name, 'sent_at' => $r->sent_at, 'received_at' => $r->received_at, 'cost' => $r->cost === null ? null : self::money($r->cost),
        ]);
    }

    private function cycles(DashboardContext $context): Builder
    {
        $select = function (string $table, string $type) use ($context) {
            $q = DB::table("{$table} as c")->join('tires as t', 't.id', '=', 'c.tire_id')
                ->where('c.tenant_id', $context->tenantId)->whereNotNull('c.received_at')->whereNull('t.deleted_at')
                ->where('c.received_at', '>=', $context->periodStartDate())->where('c.received_at', '<', $context->periodEndDateExclusive())
                ->selectRaw("c.id, '{$type}' as type, c.tire_id, t.serial_number, c.cycle_number, c.partner_id, c.sent_at, c.received_at, c.cost,
                    to_char(c.received_at, 'YYYY-MM') as month");

            return $this->scopeTires($q, $context);
        };

        return $select('tire_retreads', 'RETREAD')->unionAll($select('tire_repairs', 'REPAIR'));
    }
}
