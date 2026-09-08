<?php

namespace App\Http\Controllers\Api\Tenant\Analytics;

class MaintenanceAnalyticsController extends AnalyticsDomainController
{
    protected function collection(): string
    {
        return 'daily_maintenance_metrics';
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
        return ['maintenance_compliance', 'preventive_maintenance_ratio', 'repeat_repair_rate'];
    }
}
