<?php

namespace App\Domain\Dashboard\Widgets\Finance;

use App\Domain\Dashboard\DashboardContext;

/**
 * FN-01 Service Cost per month (no budget comparison): stacked columns PARTS / EXTERNAL_SERVICE /
 * EXTERNAL_WO for N full months plus the running month; empty months are zero. Drill-down:
 * month → vehicles → source transactions. Definition: ServiceCostQuery.
 */
class ServiceCostMonthlyWidget extends ServiceCostWidget
{
    public function id(): string
    {
        return 'FN-01';
    }

    public function compute(DashboardContext $context): array
    {
        $rows = ServiceCostQuery::transactions($context)
            ->groupBy('tx.month')
            ->selectRaw('tx.month, '.self::pivotSelect().', count(distinct tx.work_order_id) as work_orders, count(distinct tx.vehicle_id) as vehicles')
            ->get()->keyBy('month');

        $months = [];
        foreach ($context->months() as $month) {
            $row = $rows[$month] ?? null;
            $months[] = ['month' => $month, 'is_current' => $month === $context->currentMonth(),
                'work_orders' => (int) ($row->work_orders ?? 0), 'vehicles' => (int) ($row->vehicles ?? 0)] + self::sourceSums($row ?? []);
        }

        return [
            'data' => [
                'months' => $months,
                'totals' => self::totals(ServiceCostQuery::transactions($context)),
                'vehicles' => self::vehicleCount($context),
                'pending' => ServiceCostQuery::pending($context),
            ],
            'limitations' => ServiceCostQuery::foreignCurrencyLimitations($context),
        ];
    }

    public function detailRules(): ?array
    {
        return ['month' => ['required', 'date_format:Y-m'], 'vehicle_id' => ['nullable', 'uuid']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        if (! in_array($params['month'], $context->months(), true)) {
            return ['items' => [], 'meta' => ['page' => 1, 'per_page' => 20, 'total' => 0, 'last_page' => 1]];
        }
        [$from, $to] = $context->monthDateBounds($params['month']);
        $transactions = ServiceCostQuery::transactions($context, $from, $to);

        if (! empty($params['vehicle_id'])) {
            return $this->paginate($this->transactionRows($transactions->where('tx.vehicle_id', $params['vehicle_id'])), $params,
                fn ($r) => $this->presentTransaction($r));
        }

        return $this->paginate($this->perVehicle($transactions), $params, fn ($r) => $this->presentVehicle($r));
    }
}
