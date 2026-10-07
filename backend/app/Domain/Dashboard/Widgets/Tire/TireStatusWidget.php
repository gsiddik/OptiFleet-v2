<?php

namespace App\Domain\Dashboard\Widgets\Tire;

use App\Domain\Dashboard\DashboardContext;

/** TR-01 Tire Status — where the tires are now, grouped by current status (terminal states excluded). */
class TireStatusWidget extends TireWidget
{
    /** Group → statuses. SCRAPPED / SOLD / LOST are terminal and not part of the active tire stock. */
    public const GROUPS = [
        'IN_SERVICE' => ['INSTALLED', 'IN_USE'],
        'IN_STOCK' => ['IN_STOCK', 'RESERVED', 'REUSE'],
        'IN_PROCESS' => ['REMOVED', 'UNDER_INSPECTION', 'HOLD', 'QUARANTINED'],
        'AT_VENDOR' => ['RETREAD', 'REPAIR'],
    ];

    public function id(): string
    {
        return 'TR-01';
    }

    public function compute(DashboardContext $context): array
    {
        $statuses = array_merge(...array_values(self::GROUPS));
        $counts = self::countsByKey($this->tires($context)->whereIn('t.current_status', $statuses), 't.current_status', $statuses);
        $groups = [];
        foreach (self::GROUPS as $group => $members) {
            $groups[$group] = array_sum(array_intersect_key($counts, array_flip($members)));
        }

        return ['data' => ['total' => array_sum($counts), 'by_group' => $groups, 'by_status' => $counts]];
    }
}
