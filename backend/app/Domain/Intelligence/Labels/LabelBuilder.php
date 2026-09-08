<?php

namespace App\Domain\Intelligence\Labels;

use Carbon\CarbonImmutable;

/**
 * Phase 7 Section 41: builds a supervised-learning label for one
 * (entity, feature_date) pair — strictly from data that occurred *after*
 * feature_date (the boundary VehicleFeatureExtractor etc. already cut
 * features off at), and only once the full horizon has actually elapsed
 * relative to $now. label() returning null means "not yet observable"
 * (the horizon window is still in the future) — such rows must be
 * excluded from training, never treated as a negative.
 */
interface LabelBuilder
{
    /** Must match a key in config('intelligence.model_targets'). */
    public function target(): string;

    public function entityType(): string;

    public function horizonDays(): int;

    public function label(string $tenantId, string $entityId, CarbonImmutable $featureDate, CarbonImmutable $now): ?bool;
}
