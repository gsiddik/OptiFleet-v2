<?php

namespace App\Http\Controllers\Api\Tenant\Analytics;

class FleetAnalyticsController extends AnalyticsDomainController
{
    protected function collection(): string
    {
        return 'daily_fleet_snapshots';
    }

    protected function dimensionField(): string
    {
        return 'branch_id';
    }

    protected function scopeType(): ?string
    {
        return 'branch';
    }

    protected function kpiCodes(): array
    {
        return ['fleet_availability', 'breakdown_rate'];
    }
}
