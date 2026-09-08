import { AnalyticsDomainPage } from './AnalyticsDomainPage';

export function WorkshopAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: 'Workshop Analytics',
        description: 'Throughput, queue time, and workspace utilization.',
        endpoint: '/app/analytics/workshops',
        exportSlug: 'workshops',
        permission: 'analytics.workshop.view',
        dimensionField: 'workshop_id',
        dimensionLabel: 'Workshop',
        highlightFields: [
          { key: 'workspace_utilization.percentage', label: 'Utilization %' },
          { key: 'wo_throughput', label: 'Throughput' },
          { key: 'open_wo', label: 'Open WO' },
          { key: 'overdue_wo', label: 'Overdue WO' },
          { key: 'avg_queue_time_minutes', label: 'Avg Queue (min)' },
        ],
      }}
    />
  );
}
