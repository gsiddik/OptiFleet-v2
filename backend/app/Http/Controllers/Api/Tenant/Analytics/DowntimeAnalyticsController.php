<?php

namespace App\Http\Controllers\Api\Tenant\Analytics;

/**
 * Not in Phase 6 Section 41's explicit endpoint list, but Sections 23-25
 * (downtime, MTTR, MTBF) require the data be exposed somewhere; the
 * dashboard nav (Section 44) groups it under "Breakdown & Downtime", so
 * it is added here as a sibling endpoint to /analytics/breakdowns rather
 * than folded into the breakdown payload (different dimension: vehicle,
 * not branch).
 */
class DowntimeAnalyticsController extends AnalyticsDomainController
{
    protected function collection(): string
    {
        return 'daily_downtime_metrics';
    }

    protected function dimensionField(): string
    {
        return 'vehicle_id';
    }

    protected function kpiCodes(): array
    {
        return ['mttr', 'mtbf'];
    }
}
