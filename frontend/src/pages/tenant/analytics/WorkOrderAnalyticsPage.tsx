import { AnalyticsDomainPage } from './AnalyticsDomainPage';

export function WorkOrderAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: 'Work Order Analytics',
        description: 'Created/completed/overdue Work Orders, cycle time and rework rate.',
        endpoint: '/app/analytics/work-orders',
        exportSlug: 'work-orders',
        permission: 'analytics.work_order.view',
        dimensionField: 'workshop_id',
        dimensionLabel: 'Workshop',
        highlightFields: [
          { key: 'avg_cycle_time_minutes', label: 'Avg Cycle (min)' },
          { key: 'created', label: 'Created' },
          { key: 'completed', label: 'Completed' },
          { key: 'overdue', label: 'Overdue' },
          { key: 'rework.rate_percentage', label: 'Rework %' },
        ],
      }}
    />
  );
}
