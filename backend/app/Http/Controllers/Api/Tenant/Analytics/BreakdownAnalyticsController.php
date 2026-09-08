<?php

namespace App\Http\Controllers\Api\Tenant\Analytics;

class BreakdownAnalyticsController extends AnalyticsDomainController
{
    protected function collection(): string
    {
        return 'daily_breakdown_metrics';
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
        return ['breakdown_rate'];
    }
}
