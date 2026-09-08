<?php

namespace App\Http\Controllers\Api\Tenant\Analytics;

class TireAnalyticsController extends AnalyticsDomainController
{
    protected function collection(): string
    {
        return 'daily_tire_metrics';
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
        return ['tire_cost_per_km'];
    }
}
