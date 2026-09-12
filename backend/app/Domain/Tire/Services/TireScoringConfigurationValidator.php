<?php

namespace App\Domain\Tire\Services;

use App\Domain\Configuration\Models\ConfigurationSet;

/**
 * Phase F (G-31 / BD-2 / BD-8): validates a TIRE_SCORING (REPAIR or
 * RETREAD) configuration payload shape, and — the BD-8 non-overridable
 * invariant — refuses to let a tenant-scoped version loosen a safety-
 * relevant flag (`is_critical_fail`, `eligible_for_operational_reuse`)
 * on a band whose classification label also exists in the currently
 * published platform default. This is a structural/shape validator only:
 * it does not and cannot judge whether the actual thresholds are
 * tire-engineering-correct — that judgment belongs to whoever publishes
 * the configuration (a tenant admin, acting on their own authority), not
 * to this codebase.
 */
class TireScoringConfigurationValidator
{
    public function validate(ConfigurationSet $set, array $payload): void
    {
        if (! array_key_exists('bands', $payload) || ! is_array($payload['bands']) || count($payload['bands']) === 0) {
            throw new TireException('Tire scoring configuration must define at least one "bands" entry.');
        }

        $bands = $payload['bands'];
        usort($bands, fn ($a, $b) => ($a['min_percent'] ?? 0) <=> ($b['min_percent'] ?? 0));

        $expectedMin = 0.0;
        foreach ($bands as $i => $band) {
            foreach (['min_percent', 'max_percent', 'classification', 'normalized_score', 'eligible_for_operational_reuse'] as $field) {
                if (! array_key_exists($field, $band)) {
                    throw new TireException("Band #{$i} is missing required field '{$field}'.");
                }
            }
            if (! is_numeric($band['min_percent']) || ! is_numeric($band['max_percent'])) {
                throw new TireException("Band #{$i}: min_percent/max_percent must be numeric.");
            }
            if ((float) $band['min_percent'] < 0 || (float) $band['max_percent'] <= (float) $band['min_percent']) {
                throw new TireException("Band #{$i}: max_percent must be greater than min_percent, and min_percent must not be negative.");
            }
            if ((float) $band['min_percent'] !== $expectedMin) {
                throw new TireException("Bands must be contiguous starting at 0 with no gaps or overlaps (band #{$i} expected min_percent={$expectedMin}).");
            }
            if (! is_numeric($band['normalized_score']) || (float) $band['normalized_score'] < 0 || (float) $band['normalized_score'] > 100) {
                throw new TireException("Band #{$i}: normalized_score must be numeric between 0 and 100.");
            }
            if (! is_string($band['classification']) || trim($band['classification']) === '') {
                throw new TireException("Band #{$i}: classification must be a non-empty string.");
            }
            if (! is_bool($band['eligible_for_operational_reuse'])) {
                throw new TireException("Band #{$i}: eligible_for_operational_reuse must be a boolean.");
            }
            if (array_key_exists('is_critical_fail', $band) && ! is_bool($band['is_critical_fail'])) {
                throw new TireException("Band #{$i}: is_critical_fail must be a boolean when present.");
            }
            $expectedMin = (float) $band['max_percent'];
        }
        if ($expectedMin < 100.0) {
            throw new TireException('The last band must cover up to at least 100 percent.');
        }

        if (array_key_exists('requires_ka', $payload) && ! is_bool($payload['requires_ka'])) {
            throw new TireException('requires_ka must be a boolean when present.');
        }
        $requiresKa = (bool) ($payload['requires_ka'] ?? false);

        if (array_key_exists('kf_weights', $payload) && $payload['kf_weights'] !== null) {
            if (! is_array($payload['kf_weights'])) {
                throw new TireException('kf_weights must be an object of component => weight when present.');
            }
            foreach ($payload['kf_weights'] as $component => $weight) {
                if (! in_array($component, ['spa_normalized', 'ka'], true)) {
                    throw new TireException("kf_weights has an unrecognized component '{$component}' (must be spa_normalized or ka).");
                }
                if (! is_numeric($weight) || (float) $weight < 0) {
                    throw new TireException("kf_weights.{$component} must be a non-negative number.");
                }
                if ($component === 'ka' && ! $requiresKa) {
                    throw new TireException('kf_weights cannot weight "ka" unless requires_ka is true.');
                }
            }
        }

        $this->assertDoesNotWeakenPlatformSafetyInvariants($set, $bands);
    }

    /** BD-8: a TENANT-scoped version may only tighten, never loosen, a platform-default band's safety flags (matched by classification label). */
    private function assertDoesNotWeakenPlatformSafetyInvariants(ConfigurationSet $set, array $bands): void
    {
        if (! $set->tenant_id) {
            return;
        }

        $platformSet = ConfigurationSet::query()->withoutGlobalScopes()
            ->whereNull('tenant_id')
            ->where('type', ConfigurationSet::TYPE_TIRE_SCORING)
            ->where('code', $set->code)
            ->where('scope_type', ConfigurationSet::SCOPE_TENANT)
            ->first();
        $platformVersion = $platformSet?->versions()->where('status', 'PUBLISHED')->first();
        if (! $platformVersion) {
            return;
        }

        $platformBandsByLabel = collect($platformVersion->payload['bands'] ?? [])->keyBy('classification');
        foreach ($bands as $band) {
            $platformBand = $platformBandsByLabel->get($band['classification']);
            if (! $platformBand) {
                continue;
            }
            if (($platformBand['is_critical_fail'] ?? false) && ! ($band['is_critical_fail'] ?? false)) {
                throw new TireException("Cannot publish: platform default marks classification '{$band['classification']}' as a critical safety fail — a tenant override cannot remove that.");
            }
            if (($platformBand['eligible_for_operational_reuse'] ?? true) === false && ($band['eligible_for_operational_reuse'] ?? true) === true) {
                throw new TireException("Cannot publish: platform default marks classification '{$band['classification']}' as ineligible for operational reuse — a tenant override cannot make it eligible.");
            }
        }
    }
}
