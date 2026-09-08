<?php

namespace App\Http\Controllers\Api\Tenant\Analytics;

class ProcurementAnalyticsController extends AnalyticsDomainController
{
    protected function collection(): string
    {
        return 'daily_procurement_metrics';
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
        return ['procurement_lead_time'];
    }
}
