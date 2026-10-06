import { AnalyticsDomainPage } from './AnalyticsDomainPage';
import { t } from '../../../i18n/i18n';

export function TireAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: t('analytics.sections.tireAnalytics'),
        description: t('analytics.help.statusDistributionMileageCostPerKm'),
        endpoint: '/app/analytics/tires',
        exportSlug: 'tires',
        permission: 'analytics.tire.view',
        dimensionField: 'branch_id',
        dimensionLabel: t('common.fields.branch'),
        highlightFields: [
          { key: 'tire_count', label: t('analytics.fields.totalTires') },
          { key: 'installed', label: t('analytics.fields.installed') },
          { key: 'scrapped', label: t('analytics.fields.scrapped') },
          { key: 'average_mileage', label: t('analytics.fields.avgMileage') },
          { key: 'cost_per_km', label: t('analytics.fields.costKm') },
        ],
      }}
    />
  );
}
