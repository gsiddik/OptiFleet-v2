import { AnalyticsDomainPage } from './AnalyticsDomainPage';

export function ComponentAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: 'Component Reliability Analytics',
        description: 'Failure count/rate, repeat failures, mileage to failure, and repair-vs-replace ratio, by component group.',
        endpoint: '/app/analytics/components',
        exportSlug: 'components',
        permission: 'analytics.component.view',
        dimensionField: 'component_group_id',
        dimensionLabel: 'Component Group',
        highlightFields: [
          { key: 'failure_count', label: 'Failures' },
          { key: 'failure_rate_percentage', label: 'Failure Rate %' },
          { key: 'repeat_failure', label: 'Repeat Failures' },
          { key: 'mean_mileage_to_failure', label: 'Mean Mileage to Failure' },
          { key: 'cost_by_component', label: 'Cost' },
        ],
      }}
    />
  );
}
