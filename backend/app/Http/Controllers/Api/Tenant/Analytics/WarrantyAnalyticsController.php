<?php

namespace App\Http\Controllers\Api\Tenant\Analytics;

class WarrantyAnalyticsController extends AnalyticsDomainController
{
    protected function collection(): string
    {
        return 'daily_warranty_metrics';
    }

    protected function dimensionField(): string
    {
        return 'branch_id';
    }

    protected function scopeType(): ?string
    {
        return 'branch';
    }
}
