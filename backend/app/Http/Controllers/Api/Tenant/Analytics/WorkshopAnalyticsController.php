<?php

namespace App\Http\Controllers\Api\Tenant\Analytics;

class WorkshopAnalyticsController extends AnalyticsDomainController
{
    protected function collection(): string
    {
        return 'daily_workshop_metrics';
    }

    protected function dimensionField(): string
    {
        return 'workshop_id';
    }

    protected function scopeType(): ?string
    {
        return 'workshop';
    }

    protected function kpiCodes(): array
    {
        return ['workshop_utilization'];
    }
}
