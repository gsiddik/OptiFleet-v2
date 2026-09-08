<?php

namespace App\Http\Controllers\Api\Tenant\Analytics;

class InventoryAnalyticsController extends AnalyticsDomainController
{
    protected function collection(): string
    {
        return 'daily_inventory_metrics';
    }

    protected function dimensionField(): string
    {
        return 'warehouse_id';
    }

    protected function scopeType(): ?string
    {
        return 'warehouse';
    }

    protected function kpiCodes(): array
    {
        return ['inventory_turnover_foundation', 'stockout_rate'];
    }
}
