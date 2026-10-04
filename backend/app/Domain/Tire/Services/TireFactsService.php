<?php

namespace App\Domain\Tire\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * What a tire card shows about one physical tire, computed for many tires in a few batched
 * queries (never per tire):
 *
 *   last operation   the latest executed Tire Operation on the tire; a tire that never had one
 *                    falls back to its latest installation (registration / install), labelled so
 *   usage_km         TireInventoryService::usage() — accumulated over installation periods
 *   usage_hours      TireInventoryService::usageHours() — the same periods, measured in date/time
 *   last tread depth the latest measured tread depth (inspection / operation measurement)
 */
class TireFactsService
{
    public function __construct(private readonly TireInventoryService $inventory) {}

    /**
     * @param  list<string>  $tireIds
     * @return array<string, array<string, mixed>>
     */
    public function facts(array $tireIds, string $timezone): array
    {
        $tireIds = array_values(array_unique(array_filter($tireIds)));
        if ($tireIds === []) {
            return [];
        }

        $operations = DB::table('tire_operation_items as oi')
            ->join('tire_operations as o', 'o.id', '=', 'oi.tire_operation_id')
            ->whereIn('oi.tire_id', $tireIds)->whereNotNull('oi.applied_at')->whereNull('o.cancelled_at')
            ->selectRaw('DISTINCT ON (oi.tire_id) oi.tire_id, o.operated_at as at, o.odometer')
            ->orderBy('oi.tire_id')->orderByDesc('o.operated_at')
            ->get()->keyBy('tire_id');
        $installations = DB::table('tire_installations')
            ->whereIn('tire_id', $tireIds)
            ->selectRaw('DISTINCT ON (tire_id) tire_id, installed_at as at, installation_odometer as odometer')
            ->orderBy('tire_id')->orderByDesc('installed_at')
            ->get()->keyBy('tire_id');
        $usage = $this->inventory->usage($tireIds);
        $hours = $this->inventory->usageHours($tireIds);
        $treads = $this->inventory->latestTread($tireIds);

        $facts = [];
        foreach ($tireIds as $id) {
            $last = $operations->get($id);
            $source = $last ? 'TIRE_OPERATION' : null;
            if (! $last && ($last = $installations->get($id))) {
                $source = 'INSTALLATION';
            }
            $at = $last ? CarbonImmutable::parse($last->at)->setTimezone($timezone) : null;
            $facts[$id] = [
                'last_operation_source' => $source,
                'last_operation_at' => $at?->toIso8601String(),
                'last_operation_date' => $at?->format('Y-m-d'),
                'last_operation_time' => $at?->format('H:i'),
                'last_operation_odometer' => $last?->odometer,
                'usage_km' => $usage[$id] ?? null,
                'usage_hours' => $hours[$id] ?? null,
                'last_tread_depth_mm' => $treads[$id] ?? null,
            ];
        }

        return $facts;
    }
}
