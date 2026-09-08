import { AnalyticsDomainPage } from './AnalyticsDomainPage';

export function DowntimeAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: 'MTTR / MTBF (per Vehicle)',
        description: 'Mean Time To Repair and Mean Time Between Failures, by vehicle.',
        endpoint: '/app/analytics/downtime',
        exportSlug: 'downtime',
        permission: 'analytics.breakdown.view',
        dimensionField: 'vehicle_id',
        dimensionLabel: 'Vehicle',
        highlightFields: [
          { key: 'mttr_minutes', label: 'MTTR (min)' },
          { key: 'mtbf_hours', label: 'MTBF (hrs)' },
          { key: 'avg_total_downtime_minutes', label: 'Avg Downtime (min)' },
        ],
      }}
    />
  );
}
