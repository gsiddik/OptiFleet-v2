<?php

namespace App\Http\Controllers\Api\Tenant\Analytics;

class MechanicAnalyticsController extends AnalyticsDomainController
{
    protected function collection(): string
    {
        return 'daily_mechanic_metrics';
    }

    protected function dimensionField(): string
    {
        return 'mechanic_id';
    }

    protected function kpiCodes(): array
    {
        return ['mechanic_utilization'];
    }
}
