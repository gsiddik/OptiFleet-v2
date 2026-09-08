<?php

namespace App\Domain\Intelligence\Diagnostics;

use App\Domain\MaintenanceRequest\Models\MaintenanceRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 Section 27: same vehicle + same component group recurring
 * within config('intelligence.repeat_failure.window_days') at least
 * config(...'min_occurrences') times. A diagnostic insight (why is this
 * vehicle costing repeated visits), not a forward-looking risk score.
 */
class RepeatFailureDetectionService
{
    /** @return array<int, array{component_group_id: string, occurrences: int, first_at: string, last_at: string}> */
    public function detectForVehicle(string $vehicleId, CarbonImmutable $asOf): array
    {
        $windowDays = (int) config('intelligence.repeat_failure.window_days', 45);
        $minOccurrences = (int) config('intelligence.repeat_failure.min_occurrences', 3);
        $start = $asOf->subDays($windowDays);

        $groups = MaintenanceRequest::query()->withoutGlobalScopes()
            ->where('vehicle_id', $vehicleId)
            ->whereNotNull('component_group_id')
            ->whereBetween('created_at', [$start, $asOf])
            ->select('component_group_id', DB::raw('count(*) as occurrences'), DB::raw('min(created_at) as first_at'), DB::raw('max(created_at) as last_at'))
            ->groupBy('component_group_id')
            ->having(DB::raw('count(*)'), '>=', $minOccurrences)
            ->get();

        return $groups->map(fn ($row) => [
            'component_group_id' => $row->component_group_id,
            'occurrences' => (int) $row->occurrences,
            'first_at' => CarbonImmutable::parse($row->first_at)->toIso8601String(),
            'last_at' => CarbonImmutable::parse($row->last_at)->toIso8601String(),
        ])->all();
    }
}
