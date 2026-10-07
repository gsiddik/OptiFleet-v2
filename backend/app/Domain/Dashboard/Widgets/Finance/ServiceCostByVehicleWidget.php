<?php

namespace App\Domain\Dashboard\Widgets\Finance;

use App\Domain\Dashboard\DashboardContext;

/** FN-02 Service Cost by Vehicle — top 10 vehicles over the period (+ the rest as "other"). */
class ServiceCostByVehicleWidget extends ServiceCostWidget
{
    public const TOP = 10;

    public function id(): string
    {
        return 'FN-02';
    }

    public function compute(DashboardContext $context): array
    {
        $all = $this->perVehicle(ServiceCostQuery::transactions($context))->get();
        $top = $all->take(self::TOP)->map(fn ($r) => $this->presentVehicle($r))->values()->all();
        $rest = $all->slice(self::TOP);

        return [
            'data' => [
                'vehicles' => $top,
                'other' => $rest->isEmpty() ? null : ['vehicles' => $rest->count(), 'total' => self::moneySum($rest->pluck('total'))],
                'totals' => self::totals(ServiceCostQuery::transactions($context)),
            ],
            'limitations' => ServiceCostQuery::foreignCurrencyLimitations($context),
        ];
    }

    public function detailRules(): ?array
    {
        return ['vehicle_id' => ['nullable', 'uuid']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $transactions = ServiceCostQuery::transactions($context);
        if (! empty($params['vehicle_id'])) {
            return $this->paginate($this->transactionRows($transactions->where('tx.vehicle_id', $params['vehicle_id'])), $params,
                fn ($r) => $this->presentTransaction($r));
        }

        return $this->paginate($this->perVehicle($transactions), $params, fn ($r) => $this->presentVehicle($r));
    }
}
