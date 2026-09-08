import { AnalyticsDomainPage } from './AnalyticsDomainPage';

export function BreakdownAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: 'Breakdown & Downtime Analytics',
        description: 'Breakdown counts by severity, recurring breakdowns, and response/repair time.',
        endpoint: '/app/analytics/breakdowns',
        exportSlug: 'breakdowns',
        permission: 'analytics.breakdown.view',
        dimensionField: 'branch_id',
        dimensionLabel: 'Branch',
        highlightFields: [
          { key: 'breakdown_count', label: 'Breakdowns' },
          { key: 'by_severity.immobilized', label: 'Immobilized' },
          { key: 'recurring_breakdown_vehicles', label: 'Recurring Vehicles' },
          { key: 'avg_response_time_minutes', label: 'Avg Response (min)' },
          { key: 'avg_repair_time_minutes', label: 'Avg Repair (min)' },
        ],
      }}
    />
  );
}
