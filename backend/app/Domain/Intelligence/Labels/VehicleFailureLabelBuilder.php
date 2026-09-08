<?php

namespace App\Domain\Intelligence\Labels;

use App\Domain\Breakdown\Models\Breakdown;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Phase 7 Section 12/41 — "failure" for the vehicle_failure_risk target
 * is defined as: a Breakdown reported for the vehicle, OR a component
 * scrapped off the vehicle (component_removals.disposition = SCRAP),
 * within horizonDays() after the feature date. Both are observable,
 * unambiguous operational facts already recorded by Phase 1-5 — no
 * proxy/fabricated signal.
 */
class VehicleFailureLabelBuilder implements LabelBuilder
{
    public function target(): string
    {
        return 'vehicle_failure_risk';
    }

    public function entityType(): string
    {
        return 'vehicle';
    }

    public function horizonDays(): int
    {
        return (int) config('intelligence.model_targets.vehicle_failure_risk.horizon_days', 30);
    }

    public function label(string $tenantId, string $entityId, CarbonImmutable $featureDate, CarbonImmutable $now): ?bool
    {
        $windowEnd = $featureDate->addDays($this->horizonDays());
        if ($windowEnd->isAfter($now)) {
            return null; // horizon hasn't elapsed yet — outcome not observable
        }

        $breakdown = Breakdown::query()->withoutGlobalScopes()
            ->where('vehicle_id', $entityId)
            ->whereBetween('reported_at', [$featureDate, $windowEnd])
            ->exists();

        if ($breakdown) {
            return true;
        }

        return DB::table('component_removals as cr')
            ->join('component_installations as ci', 'cr.component_installation_id', '=', 'ci.id')
            ->where('ci.vehicle_id', $entityId)
            ->where('cr.disposition', 'SCRAP')
            ->whereBetween('cr.removed_at', [$featureDate, $windowEnd])
            ->exists();
    }
}
