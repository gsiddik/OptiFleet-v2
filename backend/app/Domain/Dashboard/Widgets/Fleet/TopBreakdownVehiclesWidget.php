<?php

namespace App\Domain\Dashboard\Widgets\Fleet;

use App\Domain\Dashboard\DashboardContext;

/** FL-05 Vehicles with the most breakdowns reported in the period (top 10). */
class TopBreakdownVehiclesWidget extends BreakdownTrendWidget
{
    public const TOP = 10;

    public function id(): string
    {
        return 'FL-05';
    }

    public function compute(DashboardContext $context): array
    {
        $rows = $this->reported($context)
            ->leftJoin('vehicles as v', 'v.id', '=', 'bd.vehicle_id')
            ->leftJoin('branches as b', 'b.id', '=', 'v.branch_id')
            ->groupBy('bd.vehicle_id', 'v.registration_number', 'b.name')
            ->selectRaw('bd.vehicle_id, v.registration_number, b.name as branch_name, count(*) as total,
                count(*) filter (where bd.severity = \'IMMOBILIZED\') as immobilized')
            ->orderByDesc('total')->orderBy('v.registration_number')
            ->limit(self::TOP)->get();

        return ['data' => ['vehicles' => $rows->map(fn ($r) => [
            'vehicle_id' => $r->vehicle_id, 'registration_number' => $r->registration_number, 'branch_name' => $r->branch_name,
            'total' => (int) $r->total, 'immobilized' => (int) $r->immobilized,
        ])->all()]];
    }
}
