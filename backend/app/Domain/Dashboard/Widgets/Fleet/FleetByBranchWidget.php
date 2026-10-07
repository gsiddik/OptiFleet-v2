<?php

namespace App\Domain\Dashboard\Widgets\Fleet;

use App\Domain\Dashboard\DashboardContext;

/** FL-02 Fleet by Branch — current vehicle status per branch (horizontal stacked bar). */
class FleetByBranchWidget extends FleetStatusWidget
{
    public function id(): string
    {
        return 'FL-02';
    }

    public function compute(DashboardContext $context): array
    {
        $rows = $this->vehicles($context)
            ->leftJoin('branches as b', 'b.id', '=', 'v.branch_id')
            ->selectRaw('v.branch_id, b.name as branch_name, v.status, count(*) as c')
            ->groupBy('v.branch_id', 'b.name', 'v.status')
            ->get();

        $branches = [];
        foreach ($rows as $row) {
            $branches[$row->branch_id] ??= ['branch_id' => $row->branch_id, 'branch_name' => $row->branch_name, 'total' => 0,
                'by_status' => array_fill_keys(self::STATUSES, 0)];
            $branches[$row->branch_id]['by_status'][$row->status] = (int) $row->c;
            $branches[$row->branch_id]['total'] += (int) $row->c;
        }
        $branches = array_values($branches);
        usort($branches, fn ($a, $b) => [$b['total'], $a['branch_name']] <=> [$a['total'], $b['branch_name']]);

        return ['data' => ['branches' => $branches]];
    }
}
