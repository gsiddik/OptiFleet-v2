<?php

namespace App\Http\Controllers\Api\Tenant\Analytics;

class CostAnalyticsController extends AnalyticsDomainController
{
    protected function collection(): string
    {
        return 'daily_cost_metrics';
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
        return ['maintenance_cost_per_vehicle', 'maintenance_cost_per_km'];
    }
}
