import { AnalyticsDomainPage } from './AnalyticsDomainPage';

export function CostAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: 'Maintenance Cost Analytics',
        description: 'Parts/tire/component cost from recorded cost snapshots, and cost per vehicle/km/operating hour.',
        endpoint: '/app/analytics/cost',
        exportSlug: 'cost',
        permission: 'analytics.cost.view',
        dimensionField: 'branch_id',
        dimensionLabel: 'Branch',
        highlightFields: [
          { key: 'total_maintenance_cost', label: 'Total Cost' },
          { key: 'parts_cost', label: 'Parts Cost' },
          { key: 'tire_cost', label: 'Tire Cost' },
          { key: 'component_replacement_cost', label: 'Component Cost' },
          { key: 'cost_per_vehicle', label: 'Cost / Vehicle' },
          { key: 'cost_per_km', label: 'Cost / Km' },
        ],
      }}
    />
  );
}
