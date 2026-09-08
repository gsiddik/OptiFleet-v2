<?php

namespace App\Domain\Analytics\Contracts;

use App\Domain\Analytics\Support\EtlDatasetResult;
use Carbon\CarbonImmutable;

/**
 * One dataset extractor = one PostgreSQL -> Mongo analytical projection
 * (Phase 6 Section 1). Each extractor is responsible for its own
 * extraction cutoff (via BusinessDateResolver), transformation,
 * validation (Section 16), and idempotent upsert (Section 9) into its own
 * collection — the orchestration layer (Jobs) only tracks run metadata.
 */
interface DatasetExtractor
{
    /** Stable machine key, e.g. "fleet_snapshot". Used as EtlRun.job_type. */
    public function key(): string;

    /** Human label for ETL admin UI. */
    public function label(): string;

    /**
     * Bumped when the projection's shape/formula changes in a
     * backward-incompatible way. Stored on the run + kept out of the
     * snapshot documents' own natural key (Section 9) — re-running always
     * replaces the prior projection for the same business key in place,
     * which is what makes late-arriving corrections (Section 11) safe.
     */
    public function version(): string;

    public function run(string $tenantId, CarbonImmutable $businessDate): EtlDatasetResult;
}
