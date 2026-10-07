<?php

namespace App\Domain\Dashboard\Widgets\Maintenance;

use App\Domain\Dashboard\DashboardContext;

/**
 * MT-02 Overdue Maintenance — schedules whose computed status is OVERDUE, most overdue first
 * (days past next_due_date, then km past next_due_odometer from the vehicle's current odometer).
 */
class OverdueMaintenanceWidget extends ScheduleStatusWidget
{
    public function id(): string
    {
        return 'MT-02';
    }

    public function compute(DashboardContext $context): array
    {
        $base = $this->schedules($context)->where('s.status', 'OVERDUE');
        $count = (clone $base)->count();
        $top = $this->ordered($this->rows($base))->limit(10)->get()->map(fn ($r) => $this->present($r, $context))->all();

        return ['data' => ['count' => $count, 'items' => $top]];
    }

    public function detailRules(): ?array
    {
        return [];
    }

    public function detail(DashboardContext $context, array $params): array
    {
        return $this->paginate($this->ordered($this->rows($this->schedules($context)->where('s.status', 'OVERDUE'))), $params,
            fn ($r) => $this->present($r, $context));
    }

    private function ordered($query)
    {
        return $query->orderByRaw('s.next_due_date asc nulls last')
            ->orderByRaw('(v.current_odometer - s.next_due_odometer) desc nulls last')
            ->orderBy('v.registration_number');
    }
}
