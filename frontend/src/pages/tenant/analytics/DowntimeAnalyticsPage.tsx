import { AnalyticsDomainPage } from './AnalyticsDomainPage';
import { t } from '../../../i18n/i18n';

export function DowntimeAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: t('analytics.sections.mttrMtbfPerVehicle'),
        description: t('analytics.help.meanTimeRepairMeanTimeBetween'),
        endpoint: '/app/analytics/downtime',
        exportSlug: 'downtime',
        permission: 'analytics.breakdown.view',
        dimensionField: 'vehicle_id',
        dimensionLabel: t('common.fields.vehicle'),
        highlightFields: [
          { key: 'mttr_minutes', label: t('analytics.fields.mttrMin') },
          { key: 'mtbf_hours', label: t('analytics.fields.mtbfHrs') },
          { key: 'avg_total_downtime_minutes', label: t('analytics.fields.avgDowntimeMin') },
        ],
      }}
    />
  );
}
