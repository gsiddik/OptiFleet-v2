<?php

namespace App\Domain\Tire\Inspection;

use App\Domain\Tire\Models\TireUsedInspection;

/**
 * Usage restrictions (rule profile application limits) a REUSE tire carries from the inspection
 * that returned it to stock. Position limits are a warning only at installation — they become a
 * block once position codes are standardized across wheel configurations.
 */
class UsedTireUsageRestrictions
{
    /**
     * @param  list<string>  $tireIds
     * @return array<string, array<string, mixed>> tire id => non-empty limits of its latest approved inspection, when that was REUSE
     */
    public function forTires(array $tireIds): array
    {
        if ($tireIds === []) {
            return [];
        }
        $latest = TireUsedInspection::query()->withoutGlobalScopes()->whereIn('tire_id', $tireIds)
            ->where('status', TireUsedInspection::APPROVED)->orderByDesc('approved_at')->get(['tire_id', 'final_disposition', 'thresholds'])
            ->unique('tire_id');

        $out = [];
        foreach ($latest as $inspection) {
            if ($inspection->final_disposition !== 'REUSE') {
                continue;
            }
            $limits = array_filter($inspection->thresholds['application_limits'] ?? [], fn ($v) => $v !== null && $v !== '' && $v !== []);
            if ($limits !== []) {
                $out[$inspection->tire_id] = $limits;
            }
        }

        return $out;
    }

    /** A warning when the limits name allowed positions and the target position is not one of them. */
    public function positionWarning(string $serialNumber, array $limits, string $position): ?string
    {
        $allowed = array_values(array_filter(array_map(fn ($p) => trim((string) $p), $limits['positions'] ?? [])));
        if ($allowed === [] || in_array(strtoupper(trim($position)), array_map('strtoupper', $allowed), true)) {
            return null;
        }

        return "Serial {$serialNumber} is restricted to position(s) ".implode(', ', $allowed)." by its used tire inspection, but is planned for {$position}. Installation is allowed — check the restriction before fitting.";
    }
}
