import { AnalyticsDomainPage } from './AnalyticsDomainPage';
import { t } from '../../../i18n/i18n';

export function ComponentAnalyticsPage() {
  return (
    <AnalyticsDomainPage
      config={{
        title: t('analytics.sections.componentReliabilityAnalytics'),
        description: t('analytics.help.failureCountRateRepeatFailuresMileage'),
        endpoint: '/app/analytics/components',
        exportSlug: 'components',
        permission: 'analytics.component.view',
        dimensionField: 'component_group_id',
        dimensionLabel: t('common.fields.componentGroup'),
        highlightFields: [
          { key: 'failure_count', label: t('analytics.fields.failures') },
          { key: 'failure_rate_percentage', label: t('analytics.fields.failureRatePercent') },
          { key: 'repeat_failure', label: t('analytics.fields.repeatFailures') },
          { key: 'mean_mileage_to_failure', label: t('analytics.fields.meanMileageToFailure') },
          { key: 'cost_by_component', label: t('analytics.fields.cost') },
        ],
      }}
    />
  );
}
