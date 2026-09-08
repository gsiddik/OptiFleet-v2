<?php

namespace App\Http\Controllers\Api\Tenant\Analytics;

class WorkOrderAnalyticsController extends AnalyticsDomainController
{
    protected function collection(): string
    {
        return 'daily_work_order_metrics';
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
        return ['work_order_cycle_time', 'rework_rate'];
    }
}
