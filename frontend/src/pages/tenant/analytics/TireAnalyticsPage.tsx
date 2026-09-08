import { AnalyticsDomainPage } from './AnalyticsDomainPage';

export function TireAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: 'Tire Analytics',
        description: 'Status distribution, mileage, cost per km, and failure reasons.',
        endpoint: '/app/analytics/tires',
        exportSlug: 'tires',
        permission: 'analytics.tire.view',
        dimensionField: 'branch_id',
        dimensionLabel: 'Branch',
        highlightFields: [
          { key: 'tire_count', label: 'Total Tires' },
          { key: 'installed', label: 'Installed' },
          { key: 'scrapped', label: 'Scrapped' },
          { key: 'average_mileage', label: 'Avg Mileage' },
          { key: 'cost_per_km', label: 'Cost / Km' },
        ],
      }}
    />
  );
}
