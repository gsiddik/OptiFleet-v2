<?php

namespace App\Domain\Tire\Services;

use App\Domain\Tire\Models\Tire;
use App\Domain\Tire\Models\TireRemoval;
use App\Domain\Tire\Models\TireScoringResult;
use Carbon\Carbon;

/**
 * R2 (Production Readiness — supersedes part of BD-6's "needs specialist
 * input"): evaluates a tire against its tenant's ACTIVE (published)
 * TIRE_SCORING configuration's `legal_restrictions`, `casing_eligibility`,
 * and `lifecycle_limits` — the three categories this phase's authoritative
 * business decision made required, configurable inputs. Deliberately
 * excludes vehicle/axle-suitability and any Tire-specialist/safety-
 * department approval step — neither is part of this phase's scope (see
 * IMPROVEMENT_CONTEXT.md's R2 decision record).
 *
 * Zero default behavior change: if a tenant has not published a
 * TIRE_SCORING configuration for the given scoring type at all, this
 * returns `applicable: false, eligible: true` — the same "framework
 * implemented, disabled until configured" pattern already established for
 * PO tiered approval (G-06) and Tire scoring itself (Phase F). Missing
 * tire-history data is fail-closed (BLOCK) for casing eligibility always,
 * and for lifecycle limits per the configuration's own explicit
 * `missing_data_behavior` — never silently treated as passing.
 */
class TireDispositionEligibilityService
{
    public function __construct(private readonly TireScoringConfigurationService $configuration) {}

    /**
     * @return array{applicable: bool, eligible: bool, blocking_reasons: string[], warnings: string[], configuration_version_id: ?string, metrics: array{age_months: ?float, mileage_km: ?float, repair_count: int, retread_count: int}}
     */
    public function evaluate(Tire $tire, string $scoringType): array
    {
        $version = $this->configuration->resolveEffective($scoringType, $tire->tenant_id);
        if (! $version) {
            return $this->notApplicable();
        }

        $payload = $version->payload;
        $legalRestrictions = $payload['legal_restrictions'] ?? [];
        $casing = $payload['casing_eligibility'] ?? null;
        $lifecycle = $payload['lifecycle_limits'] ?? null;

        $ageMonths = $tire->purchase_date ? (float) Carbon::parse($tire->purchase_date)->diffInMonths(now()) : null;
        $mileageKm = $this->computeLifetimeMileageKm($tire);
        $repairCount = $tire->repairs()->count();
        $retreadCount = $tire->retreads()->count();

        $blocking = [];
        $warnings = [];

        foreach ($legalRestrictions as $restriction) {
            if (! in_array($restriction['applicable_process'], [$scoringType, 'BOTH'], true)) {
                continue;
            }
            $effective = Carbon::parse($restriction['effective_date']);
            $expiry = ! empty($restriction['expiry_date']) ? Carbon::parse($restriction['expiry_date']) : null;
            if (now()->lt($effective) || ($expiry && now()->gt($expiry))) {
                continue;
            }
            $message = "Legal/policy restriction '{$restriction['name']}' ({$restriction['code']}) applies.";
            $restriction['behavior'] === 'BLOCK' ? $blocking[] = $message : $warnings[] = $message;
        }

        if ($casing) {
            if ($casing['require_inspection'] && ! $tire->inspections()->exists()) {
                $blocking[] = 'Casing eligibility requires at least one recorded inspection, and none exists for this tire.';
            }
            if ($casing['exclude_if_critical_fail'] && TireScoringResult::query()->where('tire_id', $tire->id)->where('critical_safety_fail', true)->exists()) {
                $blocking[] = 'This tire has a critical safety fail on record — its casing is excluded from further eligibility.';
            }
            // Casing eligibility is always fail-closed on missing data (no configurable override) —
            // this is the specific check for whether the CASING may be reused at all.
            foreach (['max_previous_repairs' => $repairCount, 'max_previous_retreads' => $retreadCount, 'max_age_months' => $ageMonths, 'max_mileage_km' => $mileageKm] as $field => $actual) {
                if ($casing[$field] === null) {
                    continue;
                }
                if ($actual === null) {
                    $blocking[] = "Casing eligibility requires {$field}, which cannot be determined for this tire (fail-closed).";

                    continue;
                }
                if ($actual > (float) $casing[$field]) {
                    $blocking[] = "Casing eligibility limit exceeded for {$field} ({$actual} > {$casing[$field]}).";
                }
            }
        }

        if ($lifecycle) {
            $behavior = $lifecycle['missing_data_behavior'];
            $inclusive = (bool) $lifecycle['boundary_inclusive'];
            foreach (['max_age_months' => $ageMonths, 'max_mileage_km' => $mileageKm, 'max_repair_count' => $repairCount, 'max_retread_count' => $retreadCount] as $field => $actual) {
                if ($lifecycle[$field] === null) {
                    continue;
                }
                if ($actual === null) {
                    $message = "Lifecycle limit for {$field} cannot be evaluated — required tire history data is missing.";
                    $behavior === 'BLOCK' ? $blocking[] = $message : $warnings[] = $message;

                    continue;
                }
                $limit = (float) $lifecycle[$field];
                $exceeded = $inclusive ? $actual >= $limit : $actual > $limit;
                if ($exceeded) {
                    $blocking[] = "Lifecycle limit reached for {$field} ({$actual} vs. limit {$limit}).";
                }
            }
        }

        return [
            'applicable' => true,
            'eligible' => empty($blocking),
            'blocking_reasons' => $blocking,
            'warnings' => $warnings,
            'configuration_version_id' => $version->id,
            'metrics' => ['age_months' => $ageMonths, 'mileage_km' => $mileageKm, 'repair_count' => $repairCount, 'retread_count' => $retreadCount],
        ];
    }

    /** @throws TireException if evaluation finds this tire ineligible and a config is active for this scoring type. */
    public function assertEligible(Tire $tire, string $scoringType): void
    {
        $result = $this->evaluate($tire, $scoringType);
        if (! $result['eligible']) {
            throw new TireException('Tire disposition eligibility check failed: '.implode(' ', $result['blocking_reasons']));
        }
    }

    private function notApplicable(): array
    {
        return [
            'applicable' => false, 'eligible' => true, 'blocking_reasons' => [], 'warnings' => [],
            'configuration_version_id' => null, 'metrics' => ['age_months' => null, 'mileage_km' => null, 'repair_count' => 0, 'retread_count' => 0],
        ];
    }

    /** Sums odometer deltas across every installation cycle; returns null (unknown, not zero) if any cycle's mileage cannot be determined. */
    private function computeLifetimeMileageKm(Tire $tire): ?float
    {
        $installations = $tire->installations()->orderBy('installed_at')->get();
        if ($installations->isEmpty()) {
            return 0.0;
        }

        $total = 0.0;
        foreach ($installations as $installation) {
            $startOdometer = $installation->installation_odometer !== null ? (float) $installation->installation_odometer : null;
            if ($startOdometer === null) {
                return null;
            }

            $removal = TireRemoval::query()->where('tire_installation_id', $installation->id)->first();
            $endOdometer = null;
            if ($removal && $removal->removal_odometer !== null) {
                $endOdometer = (float) $removal->removal_odometer;
            } elseif ($tire->current_status === 'INSTALLED' && $tire->current_vehicle_id === $installation->vehicle_id && ! $installation->removed_at) {
                $endOdometer = $tire->currentVehicle?->current_odometer !== null ? (float) $tire->currentVehicle->current_odometer : null;
            }

            if ($endOdometer === null) {
                return null;
            }

            $total += max(0.0, $endOdometer - $startOdometer);
        }

        return $total;
    }
}
