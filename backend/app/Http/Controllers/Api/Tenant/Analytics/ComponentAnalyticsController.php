<?php

namespace App\Http\Controllers\Api\Tenant\Analytics;

class ComponentAnalyticsController extends AnalyticsDomainController
{
    protected function collection(): string
    {
        return 'daily_component_failure_metrics';
    }

    protected function dimensionField(): string
    {
        return 'component_group_id';
    }
}
