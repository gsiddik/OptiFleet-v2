<?php

namespace App\Domain\Dashboard\Widgets\Finance;

use App\Domain\Dashboard\DashboardContext;

/**
 * FN-03 Service Cost by Branch — the Work Order's business branch (work_orders.branch_id, recorded when
 * the WO was created), not the vehicle's current branch. Only Work Orders the user may access count.
 */
class ServiceCostByBranchWidget extends ServiceCostWidget
{
    public function id(): string
    {
        return 'FN-03';
    }

    public function compute(DashboardContext $context): array
    {
        $rows = ServiceCostQuery::transactions($context)
            ->leftJoin('branches as b', 'b.id', '=', 'tx.branch_id')
            ->groupBy('tx.branch_id', 'b.name')
            ->selectRaw('tx.branch_id, b.name as branch_name, count(distinct tx.vehicle_id) as vehicles, '.self::pivotSelect())
            ->orderByRaw('sum(tx.amount) desc')
            ->get();

        return [
            'data' => [
                'branches' => $rows->map(fn ($r) => ['branch_id' => $r->branch_id, 'branch_name' => $r->branch_name, 'vehicles' => (int) $r->vehicles]
                    + self::sourceSums($r))->values()->all(),
                'totals' => self::totals(ServiceCostQuery::transactions($context)),
            ],
            'limitations' => ServiceCostQuery::foreignCurrencyLimitations($context),
        ];
    }

    public function detailRules(): ?array
    {
        // Attribution branch of the cost (not the global branch filter): narrows inside the accessible Work Orders.
        return ['attributed_branch_id' => ['required', 'uuid']];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        $transactions = ServiceCostQuery::transactions($context)->where('tx.branch_id', $params['attributed_branch_id']);

        return $this->paginate($this->perVehicle($transactions), $params, fn ($r) => $this->presentVehicle($r));
    }
}
