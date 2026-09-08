<?php

namespace App\Domain\Analytics\Services;

use Carbon\CarbonImmutable;

/**
 * Phase 6 Section 17 — every analytics API response carries this so the
 * dashboard never presents yesterday's snapshot as if it were real-time
 * without saying so.
 */
class DataFreshnessService
{
    public function __construct(private readonly EtlRunService $runs) {}

    public function forTenant(string $tenantId): array
    {
        $lastSuccess = $this->runs->lastSuccessfulAt($tenantId);

        return [
            'data_as_of' => $lastSuccess?->format('Y-m-d'),
            'last_successful_etl_at' => $lastSuccess?->toIso8601String(),
            'is_stale' => $lastSuccess === null || $lastSuccess->lt(CarbonImmutable::now()->subDay()),
        ];
    }
}
