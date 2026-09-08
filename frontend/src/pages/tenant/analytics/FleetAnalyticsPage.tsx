import { AnalyticsDomainPage } from './AnalyticsDomainPage';

export function FleetAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: 'Fleet Analytics',
        description: 'Vehicle status distribution and availability.',
        endpoint: '/app/analytics/fleet',
        exportSlug: 'fleet',
        permission: 'analytics.fleet.view',
        dimensionField: 'branch_id',
        dimensionLabel: 'Branch',
        highlightFields: [
          { key: 'availability.percentage', label: 'Availability %' },
          { key: 'total_vehicles', label: 'Total Vehicles' },
          { key: 'active_vehicles', label: 'Active' },
          { key: 'breakdown', label: 'Breakdown' },
          { key: 'in_maintenance', label: 'In Maintenance' },
        ],
      }}
    />
  );
}
