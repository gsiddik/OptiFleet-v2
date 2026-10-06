import { AnalyticsDomainPage } from './AnalyticsDomainPage';
import { t } from '../../../i18n/i18n';

export function FleetAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: t('analytics.sections.fleetAnalytics'),
        description: t('analytics.help.vehicleStatusDistributionAvailability'),
        endpoint: '/app/analytics/fleet',
        exportSlug: 'fleet',
        permission: 'analytics.fleet.view',
        dimensionField: 'branch_id',
        dimensionLabel: t('common.fields.branch'),
        highlightFields: [
          { key: 'availability.percentage', label: t('analytics.fields.availabilityPercent') },
          { key: 'total_vehicles', label: t('dashboard.fields.totalVehicles') },
          { key: 'active_vehicles', label: t('common.fields.active') },
          { key: 'breakdown', label: t('dashboard.fields.breakdown') },
          { key: 'in_maintenance', label: t('dashboard.fields.inMaintenance') },
        ],
      }}
    />
  );
}
