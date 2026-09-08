<?php

namespace App\Http\Controllers\Api\Tenant\Analytics;

class VendorAnalyticsController extends AnalyticsDomainController
{
    protected function collection(): string
    {
        return 'daily_vendor_metrics';
    }

    protected function dimensionField(): string
    {
        return 'vendor_id';
    }

    protected function kpiCodes(): array
    {
        return ['vendor_on_time_delivery'];
    }
}
