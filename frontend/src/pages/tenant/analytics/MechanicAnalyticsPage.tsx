import { AnalyticsDomainPage } from './AnalyticsDomainPage';

export function MechanicAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: 'Mechanic Analytics',
        description: 'Assigned/completed jobs, labor time, and utilization against a configured standard shift.',
        endpoint: '/app/analytics/mechanics',
        exportSlug: 'mechanics',
        permission: 'analytics.mechanic.view',
        dimensionField: 'mechanic_id',
        dimensionLabel: 'Mechanic',
        highlightFields: [
          { key: 'utilization.percentage', label: 'Utilization %' },
          { key: 'assigned_jobs', label: 'Assigned' },
          { key: 'completed_jobs', label: 'Completed' },
          { key: 'workload', label: 'Open Workload' },
          { key: 'avg_job_duration_minutes', label: 'Avg Duration (min)' },
        ],
      }}
    />
  );
}
