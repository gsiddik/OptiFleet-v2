import { AnalyticsDomainPage } from './AnalyticsDomainPage';
import { t } from '../../../i18n/i18n';

export function CostAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: t('analytics.sections.maintenanceCostAnalytics'),
        description: t('analytics.help.partsTireComponentCostRecordedCost'),
        endpoint: '/app/analytics/cost',
        exportSlug: 'cost',
        permission: 'analytics.cost.view',
        dimensionField: 'branch_id',
        dimensionLabel: t('common.fields.branch'),
        highlightFields: [
          { key: 'total_maintenance_cost', label: t('analytics.fields.totalCost') },
          { key: 'parts_cost', label: t('analytics.fields.partsCost') },
          { key: 'tire_cost', label: t('analytics.fields.tireCost') },
          { key: 'component_replacement_cost', label: t('analytics.fields.componentCost') },
          { key: 'cost_per_vehicle', label: t('analytics.fields.costVehicle') },
          { key: 'cost_per_km', label: t('analytics.fields.costKm') },
        ],
      }}
    />
  );
}
