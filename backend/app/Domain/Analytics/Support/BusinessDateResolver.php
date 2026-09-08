<?php

namespace App\Domain\Analytics\Support;

use App\Domain\Identity\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * Phase 6 Sections 7 & 56 — the single place tenant business-date/timezone
 * arithmetic happens. Storage (PostgreSQL and Mongo timestamps alike)
 * stays UTC; "business_date" is always the tenant's own calendar date.
 */
class BusinessDateResolver
{
    public function timezoneFor(?Tenant $tenant): string
    {
        return ($tenant?->timezone) ?: config('analytics.default_timezone', 'UTC');
    }

    public function todayFor(?Tenant $tenant): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezoneFor($tenant))->startOfDay();
    }

    public function yesterdayFor(?Tenant $tenant): CarbonImmutable
    {
        return $this->todayFor($tenant)->subDay();
    }

    /**
     * UTC [start, end) instant bounds for a tenant's business date — the
     * consistent read boundary (Section 15) every extractor must use so a
     * snapshot never mixes rows from two different business days because
     * of timezone drift.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function utcBoundsForBusinessDate(?Tenant $tenant, string $businessDate): array
    {
        $tz = $this->timezoneFor($tenant);
        $start = CarbonImmutable::createFromFormat('Y-m-d H:i:s', $businessDate.' 00:00:00', $tz);
        $end = $start->addDay();

        return [$start->utc(), $end->utc()];
    }
}
